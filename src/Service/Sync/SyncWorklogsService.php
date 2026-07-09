<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\DTO\Jira\JiraWorklogFeedPage;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\SyncAction;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogField;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Repository\EntryRepository;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\WorklogSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

use function array_chunk;
use function array_key_exists;
use function array_map;
use function array_values;
use function spl_object_id;
use function sprintf;
use function substr;

use const PHP_INT_MAX;

/**
 * ADR-023 Phase 3: incremental bidirectional sync. Consumes Jira's worklog/updated and
 * worklog/deleted feeds from a per-ticket-system cursor and executes the reconciliation
 * matrix with real writes: lease-checked pushes, pulls/merges into TT, delete/move
 * handling, and optional unattended import of unmatched remote worklogs. Never
 * dispatches EntryEvent — sync writes must not echo back to Jira.
 */
class SyncWorklogsService extends AbstractSyncRunService
{
    private const int MAX_FEED_PAGES = 20;

    private const int WORKLOG_ID_CHUNK_SIZE = 1000;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
        private readonly WorklogSyncStateRepository $worklogSyncStateRepository,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly EntryWorklogProjector $entryWorklogProjector,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly ReconciliationService $reconciliationService,
        private readonly WorklogWriteService $worklogWriteService,
        private readonly EntryPullApplier $entryPullApplier,
        private readonly ImportWorklogsService $importWorklogsService,
        private readonly JiraAuthorMapper $jiraAuthorMapper,
        private readonly DayClassService $dayClassService,
        ClockInterface $clock,
    ) {
        parent::__construct($entityManager, $clock);
    }

    public function sync(TicketSystem $ticketSystem, ?int $sinceMillisOverride = null, bool $dryRun = false): SyncRun
    {
        $syncUser = $ticketSystem->getSyncUser();
        $since = $sinceMillisOverride ?? $ticketSystem->getWorklogSyncCursor();

        $syncRun = new SyncRun()
            ->setType(SyncRunType::SYNC)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setTicketSystem($ticketSystem)
            ->setScope(['since' => $since, 'dry_run' => $dryRun])
            ->setCounters([])
            ->setStartedAt($this->now());

        if ($syncUser instanceof User) {
            $syncRun->setTriggeredBy($syncUser);
        }

        return $this->executeRun($syncRun, function () use ($syncRun, $ticketSystem, $syncUser, $since, $dryRun): void {
            $this->run($syncRun, $ticketSystem, $syncUser, $since, $dryRun);
        });
    }

    private function run(SyncRun $syncRun, TicketSystem $ticketSystem, ?User $syncUser, ?int $since, bool $dryRun): void
    {
        if (!$syncUser instanceof User) {
            throw new InvalidArgumentException('No sync user configured for ticket system ' . (int) $ticketSystem->getId());
        }

        if (null === $since) {
            throw new InvalidArgumentException('No cursor yet; pass --since for the first run');
        }

        $api = $this->jiraOAuthApiFactory->create($syncUser, $ticketSystem);
        $context = new SyncRunContext($syncRun, $ticketSystem, $api, $dryRun);

        $updatedIds = $this->collectFeed($context, $since, static fn (int $cursor): JiraWorklogFeedPage => $api->getWorklogsUpdatedSince($cursor));
        $deletedIds = $this->collectFeed($context, $since, static fn (int $cursor): JiraWorklogFeedPage => $api->getDeletedWorklogsSince($cursor));

        $this->processUpdatedWorklogs($context, $updatedIds);

        foreach ($deletedIds as $deletedId) {
            $this->processDeletedWorklog($context, $deletedId);
        }

        $this->handleUnmatched($context);

        $this->entityManager->flush();

        // Ids exist only after the flush above (same post-flush id rule as import).
        foreach ($context->affectedDays as $affected) {
            $this->dayClassService->recalculate((int) $affected['user']->getId(), $affected['day']);
        }

        // Inside the run body so a FAILED run never advances the cursor.
        if (!$dryRun && $context->newCursor > 0) {
            $ticketSystem->setWorklogSyncCursor($context->newCursor);
        }
    }

    /**
     * Pages through one feed following `until` while !lastPage, deduplicating ids.
     *
     * @param callable(int): JiraWorklogFeedPage $fetchPage
     *
     * @return list<int>
     */
    private function collectFeed(SyncRunContext $context, int $since, callable $fetchPage): array
    {
        /** @var array<int, int> $ids */
        $ids = [];
        $cursor = $since;

        for ($page = 0; $page < self::MAX_FEED_PAGES; ++$page) {
            $feedPage = $fetchPage($cursor);
            foreach ($feedPage->worklogIds as $worklogId) {
                $ids[$worklogId] = $worklogId;
            }

            if ($feedPage->until > $context->newCursor) {
                $context->newCursor = $feedPage->until;
            }

            if ($feedPage->lastPage) {
                return array_values($ids);
            }

            $cursor = $feedPage->until;
        }

        $this->addItem($context->syncRun, SyncItemKind::TRUNCATED, reason: 'worklog feed page cap (20) hit; remaining changes are picked up by the next run');

        return array_values($ids);
    }

    /**
     * @param list<int> $updatedIds
     */
    private function processUpdatedWorklogs(SyncRunContext $context, array $updatedIds): void
    {
        foreach (array_chunk($updatedIds, self::WORKLOG_ID_CHUNK_SIZE) as $chunk) {
            foreach ($context->api->getWorklogsByIds($chunk) as $worklog) {
                $this->processUpdatedWorklog($context, $worklog);
            }
        }
    }

    private function processUpdatedWorklog(SyncRunContext $context, JiraWorkLog $worklog): void
    {
        if (null === $worklog->id) {
            return;
        }

        $issueKey = $this->resolveIssueKey($context, $worklog);
        if (null === $issueKey) {
            return; // already recorded as an error item
        }

        try {
            $snapshot = $this->remoteWorklogNormalizer->normalize($worklog, $issueKey);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $context->syncRun->incrementCounter('errors');
            $this->addItem($context->syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $worklog->id, reason: substr($invalidArgumentException->getMessage(), 0, 255));

            return;
        }

        $entry = $this->entryRepository->findOneByWorklogIdAndTicketSystem($worklog->id, $context->ticketSystem);
        if ($entry instanceof Entry) {
            $this->reconcileAndExecute($context, $entry, $snapshot, $worklog, $issueKey);

            return;
        }

        $context->unmatchedRemote[$worklog->id] = ['worklog' => $worklog, 'snapshot' => $snapshot, 'issueKey' => $issueKey];
    }

    /**
     * Resolves the feed worklog's numeric issue id to the issue key (cached);
     * records an error item when unresolvable.
     */
    private function resolveIssueKey(SyncRunContext $context, JiraWorkLog $worklog): ?string
    {
        $issueId = $worklog->issueId;
        $issueKey = null;
        if (null !== $issueId && '' !== $issueId) {
            if (!array_key_exists($issueId, $context->issueKeyCache)) {
                $context->issueKeyCache[$issueId] = $context->api->getIssueKeyById($issueId);
            }

            $issueKey = $context->issueKeyCache[$issueId];
        }

        if (null === $issueKey) {
            $context->syncRun->incrementCounter('errors');
            $this->addItem($context->syncRun, SyncItemKind::ERROR, remoteWorklogId: $worklog->id, reason: sprintf('issue key unresolvable for issue id %s', $issueId ?? '?'));

            return null;
        }

        return $issueKey;
    }

    /**
     * Executes the ADR-023 §2 matrix row for a linked (entry, remote worklog) pair.
     */
    private function reconcileAndExecute(SyncRunContext $context, Entry $entry, WorklogSnapshot $remoteSnapshot, JiraWorkLog $worklog, string $issueKey): void
    {
        $state = $this->worklogSyncStateRepository->findOneBy(['entry' => $entry]);
        $base = $state instanceof WorklogSyncState ? WorklogSnapshot::fromArray($state->getBasePayload()) : null;
        $local = $this->entryWorklogProjector->project($entry);

        $decision = $this->reconciliationService->reconcile($base, $local, $remoteSnapshot);

        switch ($decision->action) {
            case SyncAction::NONE:
                $context->syncRun->incrementCounter('in_sync');
                if (!$state instanceof WorklogSyncState && !$context->dryRun) {
                    // Bootstrap for equal pairs without a base (pre-ADR entries).
                    $this->seedState($context, $entry, $remoteSnapshot, $worklog->updated ?? '');
                }

                break;
            case SyncAction::PUSH:
                $this->handlePush($context, $entry, $issueKey);
                break;
            case SyncAction::PULL:
                $this->handlePull($context, $entry, $state, $remoteSnapshot, $decision->fields, $worklog, $issueKey);
                break;
            case SyncAction::MERGE:
                $this->handleMerge($context, $entry, $base, $remoteSnapshot, $issueKey);
                break;
            case SyncAction::CONFLICT:
                $this->handleConflict($context, $entry, $state, $decision->fields, $worklog, $issueKey);
                break;
            case SyncAction::DIVERGED:
                $context->syncRun->incrementCounter('diverged');
                $this->addItem(
                    $context->syncRun,
                    SyncItemKind::DIVERGED,
                    issueKey: $issueKey,
                    remoteWorklogId: $worklog->id,
                    entry: $entry,
                    reason: $decision->reason,
                    payload: ['local' => $local->toArray(), 'remote' => $remoteSnapshot->toArray()],
                );
                break;
            default:
                break;
        }
    }

    private function handlePush(SyncRunContext $context, Entry $entry, string $issueKey): void
    {
        if ($context->dryRun) {
            $context->syncRun->incrementCounter('would_push');

            return;
        }

        $outcome = $this->worklogWriteService->push($this->apiForEntry($context, $entry), $entry, $context->ticketSystem);
        $this->handleWriteOutcome($context, $entry, $issueKey, $outcome, 'pushed');
    }

    /**
     * @param list<WorklogField> $fields remote-changed fields to apply
     */
    private function handlePull(SyncRunContext $context, Entry $entry, ?WorklogSyncState $state, WorklogSnapshot $remoteSnapshot, array $fields, JiraWorkLog $worklog, string $issueKey): void
    {
        if ($context->dryRun) {
            $context->syncRun->incrementCounter('would_pull');

            return;
        }

        $pullResult = $this->entryPullApplier->apply($entry, $remoteSnapshot, $fields, $context->ticketSystem);
        if (!$pullResult->applied) {
            $context->syncRun->incrementCounter('errors');
            $this->addItem($context->syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $worklog->id, entry: $entry, reason: 'pull failed: ' . $pullResult->reason);

            return;
        }

        if ($state instanceof WorklogSyncState) {
            $this->refreshState($state, $remoteSnapshot, $worklog->updated ?? '');
        }

        $context->syncRun->incrementCounter('pulled');
        $this->queueAffectedDays($context, $entry, $pullResult->affectedDays);
    }

    private function handleMerge(SyncRunContext $context, Entry $entry, ?WorklogSnapshot $base, WorklogSnapshot $remoteSnapshot, string $issueKey): void
    {
        if ($context->dryRun) {
            $context->syncRun->incrementCounter('would_merge');

            return;
        }

        if (!$base instanceof WorklogSnapshot) {
            return; // MERGE is only ever decided with a base
        }

        $pullResult = $this->entryPullApplier->apply($entry, $remoteSnapshot, $base->diff($remoteSnapshot), $context->ticketSystem);
        if (!$pullResult->applied) {
            $context->syncRun->incrementCounter('errors');
            $this->addItem($context->syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $entry->getWorklogId(), entry: $entry, reason: 'merge pull failed: ' . $pullResult->reason);

            return;
        }

        $this->queueAffectedDays($context, $entry, $pullResult->affectedDays);

        // The lease write re-GETs and refreshes the base itself on WRITTEN.
        $outcome = $this->worklogWriteService->push($this->apiForEntry($context, $entry), $entry, $context->ticketSystem);
        $this->handleWriteOutcome($context, $entry, $issueKey, $outcome, 'merged');
    }

    private function handleWriteOutcome(SyncRunContext $context, Entry $entry, string $issueKey, WriteOutcome $outcome, string $successCounter): void
    {
        $syncRun = $context->syncRun;
        switch ($outcome) {
            case WriteOutcome::WRITTEN:
                $syncRun->incrementCounter($successCounter);
                break;
            case WriteOutcome::LEASE_LOST:
                $syncRun->incrementCounter('conflicts');
                $this->addItem($syncRun, SyncItemKind::CONFLICT, issueKey: $issueKey, remoteWorklogId: $entry->getWorklogId(), entry: $entry, reason: 'push lease lost: remote changed since base; parked as conflict');
                break;
            case WriteOutcome::REMOTE_MISSING:
                $syncRun->incrementCounter('orphaned');
                $this->addItem($syncRun, SyncItemKind::LOCAL_ONLY, issueKey: $issueKey, remoteWorklogId: $entry->getWorklogId(), entry: $entry, reason: 'remote worklog missing during push; parked as orphaned');
                break;
            case WriteOutcome::SKIPPED:
                $syncRun->incrementCounter('errors');
                $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, entry: $entry, reason: 'push skipped: entry has no pushable ticket');
                break;
        }
    }

    /**
     * @param list<WorklogField> $fields
     */
    private function handleConflict(SyncRunContext $context, Entry $entry, ?WorklogSyncState $state, array $fields, JiraWorkLog $worklog, string $issueKey): void
    {
        if ($state instanceof WorklogSyncState && !$context->dryRun) {
            $state->setStatus(WorklogSyncStatus::CONFLICT);
            $state->setConflictRemotePayload([
                'comment' => $worklog->comment,
                'started' => $worklog->started,
                'timeSpentSeconds' => $worklog->timeSpentSeconds,
                'updated' => $worklog->updated,
            ]);
        }

        $context->syncRun->incrementCounter('conflicts');
        $this->addItem(
            $context->syncRun,
            SyncItemKind::CONFLICT,
            issueKey: $issueKey,
            remoteWorklogId: $worklog->id,
            entry: $entry,
            reason: 'both sides changed the same field(s); parked',
            payload: ['fields' => array_map(static fn (WorklogField $worklogField): string => $worklogField->value, $fields)],
        );
    }

    private function processDeletedWorklog(SyncRunContext $context, int $deletedWorklogId): void
    {
        $entry = $this->entryRepository->findOneByWorklogIdAndTicketSystem($deletedWorklogId, $context->ticketSystem);
        if (!$entry instanceof Entry) {
            return; // never knew this worklog
        }

        $projection = $this->entryWorklogProjector->project($entry);

        // Move detection first: a delete+create pair with identical start and duration is a relink.
        foreach ($context->unmatchedRemote as $candidateWorklogId => $candidate) {
            if ($candidate['snapshot']->startedTimestamp === $projection->startedTimestamp
                && $candidate['snapshot']->durationMinutes === $projection->durationMinutes
            ) {
                $this->relink($context, $entry, $candidateWorklogId, $candidate);

                return;
            }
        }

        $state = $this->worklogSyncStateRepository->findOneBy(['entry' => $entry]);
        $base = $state instanceof WorklogSyncState ? WorklogSnapshot::fromArray($state->getBasePayload()) : null;

        if ($base instanceof WorklogSnapshot && $projection->equals($base)) {
            $this->deleteLocalEntry($context, $entry, $deletedWorklogId);

            return;
        }

        // Local dirty or no base: park instead of deleting local work.
        if ($context->dryRun) {
            $context->syncRun->incrementCounter('would_orphan');

            return;
        }

        if ($state instanceof WorklogSyncState) {
            $state->setStatus(WorklogSyncStatus::ORPHANED);
        }

        $context->syncRun->incrementCounter('orphaned');
        $this->addItem($context->syncRun, SyncItemKind::LOCAL_ONLY, issueKey: $entry->getTicket(), remoteWorklogId: $deletedWorklogId, entry: $entry, reason: 'remote worklog deleted; local entry modified — parked');
    }

    private function deleteLocalEntry(SyncRunContext $context, Entry $entry, int $deletedWorklogId): void
    {
        if ($context->dryRun) {
            $context->syncRun->incrementCounter('would_delete_local');

            return;
        }

        $user = $entry->getUser();
        if ($user instanceof User) {
            $day = $entry->getDay()->format('Y-m-d');
            $context->affectedDays[spl_object_id($user) . '|' . $day] = ['user' => $user, 'day' => $day];
        }

        $this->addItem($context->syncRun, SyncItemKind::LOCAL_ONLY, issueKey: $entry->getTicket(), remoteWorklogId: $deletedWorklogId, entry: $entry, reason: 'remote worklog deleted; local entry removed');
        $this->entityManager->remove($entry); // sync state cascades on delete
        $context->syncRun->incrementCounter('deleted_local');
    }

    /**
     * @param array{worklog: JiraWorkLog, snapshot: WorklogSnapshot, issueKey: string} $candidate
     */
    private function relink(SyncRunContext $context, Entry $entry, int $candidateWorklogId, array $candidate): void
    {
        unset($context->unmatchedRemote[$candidateWorklogId]);

        if ($context->dryRun) {
            $context->syncRun->incrementCounter('would_relink');

            return;
        }

        $entry->setWorklogId($candidateWorklogId);

        if ($entry->getTicket() !== $candidate['snapshot']->issueKey) {
            $pullResult = $this->entryPullApplier->apply($entry, $candidate['snapshot'], [WorklogField::ISSUE_KEY], $context->ticketSystem);
            if ($pullResult->applied) {
                $this->queueAffectedDays($context, $entry, $pullResult->affectedDays);
            } else {
                $context->syncRun->incrementCounter('errors');
                $this->addItem($context->syncRun, SyncItemKind::ERROR, issueKey: $candidate['issueKey'], remoteWorklogId: $candidateWorklogId, entry: $entry, reason: 'relink issue move failed: ' . $pullResult->reason);
            }
        }

        $state = $this->worklogSyncStateRepository->findOneBy(['entry' => $entry]);
        if ($state instanceof WorklogSyncState) {
            $this->refreshState($state, $candidate['snapshot'], $candidate['worklog']->updated ?? '');
        } else {
            $this->seedState($context, $entry, $candidate['snapshot'], $candidate['worklog']->updated ?? '');
        }

        $context->syncRun->incrementCounter('relinked');
    }

    /**
     * Imports (or reports) remote worklogs that matched no entry and no move.
     */
    private function handleUnmatched(SyncRunContext $context): void
    {
        if ([] === $context->unmatchedRemote) {
            return;
        }

        $activity = $context->ticketSystem->getSyncDefaultActivity();
        if (!$activity instanceof Activity) {
            foreach ($context->unmatchedRemote as $worklogId => $candidate) {
                $context->syncRun->incrementCounter('remote_only');
                $this->addItem(
                    $context->syncRun,
                    SyncItemKind::REMOTE_ONLY,
                    issueKey: $candidate['issueKey'],
                    remoteWorklogId: $worklogId,
                    author: $this->jiraAuthorMapper->remoteKey($candidate['worklog']),
                    reason: 'Jira worklog has no matching entry; no default import activity configured',
                    payload: ['remote' => $candidate['snapshot']->toArray(), 'updated' => $candidate['worklog']->updated],
                );
            }

            return;
        }

        $importRunContext = new ImportRunContext(
            syncRun: $context->syncRun,
            ticketSystem: $context->ticketSystem,
            activity: $activity,
            targetUsernames: [],
            dryRun: $context->dryRun,
            rangeFrom: 0,
            rangeTo: PHP_INT_MAX,
        );

        foreach ($context->unmatchedRemote as $candidate) {
            $this->importWorklogsService->processWorklog($importRunContext, $candidate['issueKey'], $candidate['worklog']);
        }

        foreach ($importRunContext->affectedDays as $key => $affected) {
            $context->affectedDays[$key] = $affected;
        }
    }

    /**
     * Pushes with the entry owner's token when connected, else the run's sync-user api.
     */
    private function apiForEntry(SyncRunContext $context, Entry $entry): JiraOAuthApiService
    {
        $owner = $entry->getUser();
        if (!$owner instanceof User) {
            return $context->api;
        }

        $ownerId = (int) $owner->getId();
        if (isset($context->userApiCache[$ownerId])) {
            return $context->userApiCache[$ownerId];
        }

        $api = $context->api;
        foreach ($owner->getUserTicketsystems() as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem() === $context->ticketSystem
                && '' !== $userTicketsystem->getAccessToken()
                && !$userTicketsystem->getAvoidConnection()
            ) {
                $api = $this->jiraOAuthApiFactory->create($owner, $context->ticketSystem);
                break;
            }
        }

        return $context->userApiCache[$ownerId] = $api;
    }

    private function seedState(SyncRunContext $context, Entry $entry, WorklogSnapshot $snapshot, string $updated): void
    {
        $state = new WorklogSyncState()->setEntry($entry)->setTicketSystem($context->ticketSystem);
        $this->refreshState($state, $snapshot, $updated);
        $state->setLastSyncRun($context->syncRun);
        $this->entityManager->persist($state);
    }

    private function refreshState(WorklogSyncState $state, WorklogSnapshot $snapshot, string $updated): void
    {
        $state->setStatus(WorklogSyncStatus::IN_SYNC)
            ->setBasePayload($snapshot->toArray())
            ->setBaseUpdatedAt($updated)
            ->setConflictRemotePayload(null)
            ->setLastSyncedAt($this->now());
    }

    /**
     * @param list<string> $days Y-m-d
     */
    private function queueAffectedDays(SyncRunContext $context, Entry $entry, array $days): void
    {
        $user = $entry->getUser();
        if (!$user instanceof User) {
            return;
        }

        foreach ($days as $day) {
            $context->affectedDays[spl_object_id($user) . '|' . $day] = ['user' => $user, 'day' => $day];
        }
    }
}
