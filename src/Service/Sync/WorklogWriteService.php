<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\WorklogSyncState;
use App\Enum\EntrySource;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Lease-checked (read-compare-write) Jira worklog writes (ADR-023 §1). Wraps the legacy
 * write (which owns payload format, guards and id storage) with the CAS protocol and
 * base-state maintenance. The ~seconds window between compare and write is accepted.
 */
class WorklogWriteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorklogSyncStateRepository $worklogSyncStateRepository,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     * @throws JsonException                   when a response body is not JSON
     */
    public function push(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): WriteOutcome
    {
        $ticket = $entry->getTicket();
        if ('' === $ticket || '0' === $ticket || $this->isAgentWalltime($entry)) {
            return WriteOutcome::SKIPPED;
        }

        $worklogId = $entry->getWorklogId();
        if (null === $worklogId || $worklogId <= 0) {
            $this->writeAndStoreId($api, $entry);
            $this->refreshBase($api, $entry, $ticketSystem);

            return WriteOutcome::WRITTEN;
        }

        $state = $this->worklogSyncStateRepository->findOneBy(['entry' => $entry]);
        $remote = $api->getIssueWorklog($ticket, $worklogId);

        if (!$remote instanceof JiraWorkLog) {
            if ($this->moveToTicket($api, $entry, $state)) {
                $this->refreshBase($api, $entry, $ticketSystem);

                return WriteOutcome::WRITTEN;
            }

            if ($state instanceof WorklogSyncState) {
                $state->setStatus(WorklogSyncStatus::ORPHANED);
            }

            return WriteOutcome::REMOTE_MISSING;
        }

        if ($state instanceof WorklogSyncState && ($remote->updated ?? '') !== $state->getBaseUpdatedAt()) {
            $state->setStatus(WorklogSyncStatus::CONFLICT);
            $state->setConflictRemotePayload([
                'comment' => $remote->comment,
                'started' => $remote->started,
                'timeSpentSeconds' => $remote->timeSpentSeconds,
                'updated' => $remote->updated,
            ]);

            return WriteOutcome::LEASE_LOST;
        }

        // State missing = pre-ADR entry: bootstrap the base with this first lease-era write.
        $api->updateEntryJiraWorkLog($entry);
        $this->refreshBase($api, $entry, $ticketSystem);

        return WriteOutcome::WRITTEN;
    }

    /**
     * Forced lease-era write (ADR-023 §2 conflict resolution): identical to push() but
     * skips the lease comparison. The legacy write nulls a stale worklogId and re-creates,
     * so this also covers orphaned recreation.
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     * @throws JsonException                   when a response body is not JSON
     */
    public function forcePush(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): WriteOutcome
    {
        $ticket = $entry->getTicket();
        if ('' === $ticket || '0' === $ticket || $this->isAgentWalltime($entry)) {
            return WriteOutcome::SKIPPED;
        }

        $this->writeAndStoreId($api, $entry);
        $this->refreshBase($api, $entry, $ticketSystem);

        return WriteOutcome::WRITTEN;
    }

    /**
     * #941: the entry's worklog is not on its ticket. When it still sits on another issue
     * of this Jira (a ticket change whose delete on the old issue never ran), it is
     * deleted there and booked anew on the ticket. Only the entry's own worklog moves
     * (JiraOAuthApiService::findEntryWorklog), only while its lease holds (changed in
     * Jira since the base, it stays parked for a human, ADR-023 §1; without a base, as
     * for a pre-ADR entry, there is no lease to check), and only when this user writes
     * worklogs to this Jira at all, or the write after the delete would book nothing.
     *
     * Every path that cannot finish before the delete keeps the worklog id. Pending changes
     * are flushed before the delete, so a failing flush surfaces while the old id still
     * holds. After the delete the old id names nothing: it is cleared and flushed before the new write, so
     * when that write fails the stored entry has no id and the next save or sync books it
     * anew instead of parking the deleted id as orphaned.
     *
     * @throws JiraApiException                when the delete or the new write fails
     * @throws JiraApiInvalidResourceException
     * @throws JsonException                   when a response body is not JSON
     *
     * @return bool whether the worklog was moved
     */
    private function moveToTicket(JiraOAuthApiService $api, Entry $entry, ?WorklogSyncState $state): bool
    {
        $worklogId = (int) $entry->getWorklogId();
        if (!$api->canWriteWorklogs()) {
            return false;
        }

        try {
            $found = $api->findEntryWorklog($entry);
        } catch (JiraApiUnauthorizedException $jiraApiUnauthorizedException) {
            // A refused token is not a missing worklog: the caller asks for a reconnect.
            throw $jiraApiUnauthorizedException;
        } catch (JiraApiException|JsonException $exception) {
            $this->logger?->error('Worklog lookup failed; worklog stays parked as missing', ['entry' => $entry->getId(), 'worklog' => $worklogId, 'exception' => $exception]);

            return false;
        }

        if (!$found instanceof JiraWorkLog
            || ($state instanceof WorklogSyncState && ($found->updated ?? '') !== $state->getBaseUpdatedAt())
            || !$api->doesTicketExist($entry->getTicket())
        ) {
            return false;
        }

        // Store what is pending first: a failing flush must surface while the old id
        // still names a worklog, not between the delete and the new write.
        $this->entityManager->flush();
        $api->deleteIssueWorklog((string) $found->issueId, $worklogId);
        $entry->setWorklogId(null);
        $this->entityManager->flush();
        $this->writeAndStoreId($api, $entry);
        $this->logger?->info('Worklog moved to the entry\'s ticket', ['entry' => $entry->getId(), 'worklog' => $worklogId, 'fromIssueId' => $found->issueId]);

        return true;
    }

    /**
     * Writes the worklog and stores the id Jira returned at once. The base read that
     * follows can fail; without this flush the save path never reaches its own flush,
     * the new id is lost, and the next save creates a second worklog.
     *
     * @throws JiraApiException
     * @throws JsonException    when a response body is not JSON
     */
    private function writeAndStoreId(JiraOAuthApiService $api, Entry $entry): void
    {
        $api->updateEntryJiraWorkLog($entry);
        $this->entityManager->flush();
    }

    /**
     * ADR-025 §7: a ticket-system worklog is the human labour line; agent walltime is
     * never written there, whichever caller asks.
     */
    private function isAgentWalltime(Entry $entry): bool
    {
        return EntrySource::AGENT === $entry->getSource();
    }

    /**
     * @throws JiraApiException
     * @throws JsonException    when a response body is not JSON
     *
     * @return bool whether Jira confirmed the worklog is gone (deleted, or not found)
     */
    public function delete(JiraOAuthApiService $api, Entry $entry): bool
    {
        return $api->deleteEntryJiraWorkLog($entry);
    }

    /**
     * @throws JiraApiException
     * @throws JsonException    when a response body is not JSON
     */
    private function refreshBase(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): void
    {
        $worklogId = $entry->getWorklogId();
        if (null === $worklogId || $worklogId <= 0) {
            return;
        }

        $fresh = $api->getIssueWorklog($entry->getTicket(), $worklogId);
        if (!$fresh instanceof JiraWorkLog) {
            return;
        }

        $state = $this->worklogSyncStateRepository->findOneBy(['entry' => $entry]);
        if (!$state instanceof WorklogSyncState) {
            $state = new WorklogSyncState()->setEntry($entry)->setTicketSystem($ticketSystem);
            $this->entityManager->persist($state);
        }

        $state->setStatus(WorklogSyncStatus::IN_SYNC)
            ->setBasePayload($this->remoteWorklogNormalizer->normalize($fresh, $entry->getTicket())->toArray())
            ->setBaseUpdatedAt($fresh->updated ?? '')
            ->setConflictRemotePayload(null)
            ->setLastSyncedAt(DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
