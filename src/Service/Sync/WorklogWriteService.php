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
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

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
    ) {
    }

    public function push(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): WriteOutcome
    {
        $ticket = $entry->getTicket();
        if ('' === $ticket || '0' === $ticket) {
            return WriteOutcome::SKIPPED;
        }

        $worklogId = $entry->getWorklogId();
        if (null === $worklogId || $worklogId <= 0) {
            $api->updateEntryJiraWorkLog($entry);
            $this->refreshBase($api, $entry, $ticketSystem);

            return WriteOutcome::WRITTEN;
        }

        $state = $this->worklogSyncStateRepository->findOneBy(['entry' => $entry]);
        $remote = $api->getIssueWorklog($ticket, $worklogId);

        if (!$remote instanceof JiraWorkLog) {
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
     */
    public function forcePush(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): WriteOutcome
    {
        $ticket = $entry->getTicket();
        if ('' === $ticket || '0' === $ticket) {
            return WriteOutcome::SKIPPED;
        }

        $api->updateEntryJiraWorkLog($entry);
        $this->refreshBase($api, $entry, $ticketSystem);

        return WriteOutcome::WRITTEN;
    }

    public function delete(JiraOAuthApiService $api, Entry $entry): void
    {
        $api->deleteEntryJiraWorkLog($entry);
    }

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
