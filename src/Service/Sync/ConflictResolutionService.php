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
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\ResolutionResult;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function in_array;
use function sprintf;

/**
 * Resolves parked (CONFLICT / ORPHANED) worklog sync states by picking a winner
 * (ADR-023 §2): local wins via a forced lease-era write, remote wins by pulling the
 * LIVE remote worklog — or, when the remote is gone, by deleting the local entry.
 */
class ConflictResolutionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly WorklogWriteService $worklogWriteService,
        private readonly EntryPullApplier $entryPullApplier,
        private readonly EntryWorklogProjector $entryWorklogProjector,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly DayClassService $dayClassService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function resolve(WorklogSyncState $state, string $winner, User $actor): ResolutionResult
    {
        if (!in_array($state->getStatus(), [WorklogSyncStatus::CONFLICT, WorklogSyncStatus::ORPHANED], true)) {
            return new ResolutionResult(false, '', 'state is not parked');
        }

        if (!in_array($winner, ['local', 'remote'], true)) {
            return new ResolutionResult(false, '', sprintf('invalid winner "%s"; expected "local" or "remote"', $winner));
        }

        $entry = $state->getEntry();
        $ticketSystem = $state->getTicketSystem();
        if (!$entry instanceof Entry || !$ticketSystem instanceof TicketSystem) {
            return new ResolutionResult(false, '', 'state is incomplete');
        }

        $api = $this->jiraOAuthApiFactory->create($this->tokenUser($entry, $ticketSystem, $actor), $ticketSystem);

        if ('local' === $winner) {
            return $this->resolveLocalWins($api, $state, $entry, $ticketSystem);
        }

        return $this->resolveRemoteWins($api, $state, $entry, $ticketSystem);
    }

    /**
     * Entry owner if connected, else the acting user.
     */
    private function tokenUser(Entry $entry, TicketSystem $ticketSystem, User $actor): User
    {
        $owner = $entry->getUser();
        if ($owner instanceof User) {
            foreach ($owner->getUserTicketsystems() as $userTicketsystem) {
                if ($userTicketsystem->getTicketSystem() === $ticketSystem
                    && '' !== $userTicketsystem->getAccessToken()
                    && !$userTicketsystem->getAvoidConnection()
                ) {
                    return $owner;
                }
            }
        }

        return $actor;
    }

    private function resolveLocalWins(JiraOAuthApiService $api, WorklogSyncState $state, Entry $entry, TicketSystem $ticketSystem): ResolutionResult
    {
        $wasOrphaned = WorklogSyncStatus::ORPHANED === $state->getStatus();

        $outcome = $this->worklogWriteService->forcePush($api, $entry, $ticketSystem);
        if (WriteOutcome::WRITTEN !== $outcome) {
            return new ResolutionResult(false, '', 'push skipped: entry has no pushable ticket');
        }

        // forcePush's base refresh sets IN_SYNC and clears the conflict payload —
        // but it can no-op (worklog id missing after a zero-duration delete, or the
        // fresh read 404ing in a race). A still-parked state must not report success.
        if (WorklogSyncStatus::IN_SYNC !== $state->getStatus()) {
            $this->entityManager->flush();

            return new ResolutionResult(false, '', 'push succeeded but the sync base could not be refreshed; conflict remains parked — re-run resolution');
        }

        $this->entityManager->flush();

        return new ResolutionResult(true, $wasOrphaned ? 'recreated_local' : 'pushed_local');
    }

    private function resolveRemoteWins(JiraOAuthApiService $api, WorklogSyncState $state, Entry $entry, TicketSystem $ticketSystem): ResolutionResult
    {
        if (WorklogSyncStatus::ORPHANED === $state->getStatus()) {
            // Remote is gone — remote winning means the deletion wins.
            return $this->deleteLocalEntry($entry);
        }

        $worklogId = $entry->getWorklogId();
        $live = null !== $worklogId && $worklogId > 0
            ? $api->getIssueWorklog($entry->getTicket(), $worklogId)
            : null;

        if (!$live instanceof JiraWorkLog) {
            return $this->deleteLocalEntry($entry);
        }

        return $this->pullLiveRemote($state, $entry, $ticketSystem, $live);
    }

    /**
     * Pulls the LIVE remote (the stored conflict payload is display material and may be stale).
     */
    private function pullLiveRemote(WorklogSyncState $state, Entry $entry, TicketSystem $ticketSystem, JiraWorkLog $live): ResolutionResult
    {
        $remoteSnapshot = $this->remoteWorklogNormalizer->normalize($live, $entry->getTicket());
        $fields = $this->entryWorklogProjector->project($entry)->diff($remoteSnapshot);

        $pullResult = $this->entryPullApplier->apply($entry, $remoteSnapshot, $fields, $ticketSystem);
        if (!$pullResult->applied) {
            return new ResolutionResult(false, '', $pullResult->reason);
        }

        $state->setStatus(WorklogSyncStatus::IN_SYNC)
            ->setBasePayload($remoteSnapshot->toArray())
            ->setBaseUpdatedAt($live->updated ?? '')
            ->setConflictRemotePayload(null)
            ->setLastSyncedAt(DateTimeImmutable::createFromInterface($this->clock->now()));

        $this->entityManager->flush();
        $this->recalculateDays($entry->getUser(), $pullResult->affectedDays);

        return new ResolutionResult(true, 'pulled_remote');
    }

    private function deleteLocalEntry(Entry $entry): ResolutionResult
    {
        $owner = $entry->getUser();
        $day = $entry->getDay()->format('Y-m-d');

        $this->entityManager->remove($entry); // sync state cascades on delete
        $this->entityManager->flush();
        $this->recalculateDays($owner, [$day]);

        return new ResolutionResult(true, 'deleted_local');
    }

    /**
     * @param list<string> $days Y-m-d
     */
    private function recalculateDays(?User $owner, array $days): void
    {
        if (!$owner instanceof User) {
            return;
        }

        foreach ($days as $day) {
            $this->dayClassService->recalculate((int) $owner->getId(), $day);
        }
    }
}
