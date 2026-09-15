<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Entity\WorklogSyncState;
use App\Enum\SyncAction;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogField;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Repository\EntryRepository;
use App\Repository\UserTicketsystemRepository;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Throwable;

use function array_map;
use function array_values;
use function date;
use function in_array;
use function spl_object_id;
use function sprintf;
use function substr;

use const PHP_INT_MAX;

/**
 * ADR-023 (amended): opt-in, per-user bidirectional sync. Every Jira operation runs under an
 * accountable person's own token — the author's when they opted their own worklogs in
 * (`users_ticket_systems.sync_enabled`), else a PO's when the PO opted into sync-all
 * (`sync_all`, PO is ROLE_PL/ADMIN) and can see the worklog. There is no central sync user,
 * no cursor and no worklog feed: a date window is rescanned per run, idempotent via worklog id.
 * Jira's own permission model is the access control. Never dispatches EntryEvent — sync writes
 * must not echo back to Jira.
 */
class SyncWorklogsService extends AbstractSyncRunService
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
        private readonly WorklogSyncStateRepository $worklogSyncStateRepository,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly EntryWorklogProjector $entryWorklogProjector,
        private readonly RemoteWorklogReader $remoteWorklogReader,
        private readonly ReconciliationService $reconciliationService,
        private readonly WorklogWriteService $worklogWriteService,
        private readonly EntryPullApplier $entryPullApplier,
        private readonly ImportWorklogsService $importWorklogsService,
        private readonly JiraAuthorMapper $jiraAuthorMapper,
        private readonly DayClassService $dayClassService,
        private readonly UserTicketsystemRepository $userTicketsystemRepository,
        private readonly RoleHierarchyInterface $roleHierarchy,
        ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct($entityManager, $clock);
    }

    /**
     * Sync one target user's worklogs under an accountable token owner (ADR-023 amendment).
     * When the owner is the target it is a self-sync; otherwise a PO acts under their own token.
     */
    public function syncUser(User $targetUser, User $tokenOwner, TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to, bool $dryRun = false): SyncRun
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::SYNC)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setTicketSystem($ticketSystem)
            ->setTriggeredBy($tokenOwner)
            ->setScope([
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'dry_run' => $dryRun,
                'target' => $targetUser->getUsername(),
            ])
            ->setCounters([])
            ->setStartedAt($this->now());

        return $this->executeRun($syncRun, function () use ($syncRun, $targetUser, $tokenOwner, $ticketSystem, $from, $to, $dryRun): void {
            $api = $this->jiraOAuthApiFactory->create($tokenOwner, $ticketSystem);
            $context = new SyncRunContext($syncRun, $ticketSystem, $api, $dryRun, $tokenOwner);
            [$jql, $matchesAuthor] = $this->buildRead($api, $targetUser, $tokenOwner, $ticketSystem, $from, $to);
            $this->runUserSync($context, $targetUser, $matchesAuthor, $jql, $from, $to);
        });
    }

    /**
     * Cron entry point: run both opt-in passes for a ticket system and return every run.
     *
     * @return list<SyncRun>
     */
    public function syncTicketSystem(TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to, bool $dryRun = false): array
    {
        $runs = [];

        // Pass 1 — self-sync: authors who opted their own worklogs in, under their own token.
        $selfEnabled = $this->userTicketsystemRepository->findSyncEnabled($ticketSystem);
        foreach ($selfEnabled as $userTicketsystem) {
            $user = $userTicketsystem->getUser();
            if ($user instanceof User) {
                $runs[] = $this->syncUser($user, $user, $ticketSystem, $from, $to, $dryRun);
            }
        }

        // Pass 2 — PO sync-all: cover everyone else the PO can see, under the PO's token.
        // Authors already covered by an earlier PO are skipped, so overlapping sync-all
        // POs don't produce duplicate runs / redundant Jira calls for the same author:
        // the first PO (repository order) takes responsibility for a shared author.
        $excludeAuthorKeys = $this->selfEnabledAuthorKeys($selfEnabled);
        $coveredAuthors = [];
        foreach ($this->userTicketsystemRepository->findSyncAllOwners($ticketSystem) as $ownerTicketsystem) {
            $partyOwner = $ownerTicketsystem->getUser();
            if (!$partyOwner instanceof User) {
                continue;
            }
            if (!$this->isProjectOwner($partyOwner)) {
                continue;
            }

            foreach ($this->discoverPoAuthors($partyOwner, $ticketSystem, $excludeAuthorKeys, $from, $to) as $authorUser) {
                $authorKey = $authorUser->getId() ?? spl_object_id($authorUser);
                if (isset($coveredAuthors[$authorKey])) {
                    continue;
                }

                $coveredAuthors[$authorKey] = true;
                $runs[] = $this->syncUser($authorUser, $partyOwner, $ticketSystem, $from, $to, $dryRun);
            }
        }

        return $runs;
    }

    /**
     * Whether the user is a project owner (ROLE_PL or ROLE_ADMIN) — gates PO sync-all.
     */
    private function isProjectOwner(User $user): bool
    {
        $reachable = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        return in_array('ROLE_PL', $reachable, true) || in_array('ROLE_ADMIN', $reachable, true);
    }

    /**
     * The remote author keys of self-sync-enabled users — excluded from PO coverage.
     *
     * @param list<UserTicketsystem> $selfEnabled
     *
     * @return array<string, true>
     */
    private function selfEnabledAuthorKeys(array $selfEnabled): array
    {
        $keys = [];
        foreach ($selfEnabled as $userTicketsystem) {
            $remoteAccountId = $userTicketsystem->getRemoteAccountId();
            if (null !== $remoteAccountId && '' !== $remoteAccountId) {
                $keys[$remoteAccountId] = true;
            }

            $username = $userTicketsystem->getUser()?->getUsername();
            if (null !== $username && '' !== $username) {
                $keys[$username] = true;
            }
        }

        return $keys;
    }

    /**
     * Read the PO-visible worklogs (broad, date-only) and map each covered author to a TT
     * or shadow user. Self-sync-enabled authors are excluded (pass 1 owns them).
     *
     * @param array<string, true> $excludeAuthorKeys
     *
     * @return list<User>
     */
    private function discoverPoAuthors(User $partyOwner, TicketSystem $ticketSystem, array $excludeAuthorKeys, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $api = $this->jiraOAuthApiFactory->create($partyOwner, $ticketSystem);
        $jql = sprintf('worklogDate >= "%s" AND worklogDate <= "%s"', $from->format('Y-m-d'), $to->format('Y-m-d'));

        /** @var array<string, JiraWorkLog> $representatives one worklog per covered author key */
        $representatives = [];
        $matchesAuthor = static function (JiraWorkLog $jiraWorkLog) use ($excludeAuthorKeys, &$representatives): bool {
            $key = $jiraWorkLog->authorAccountId ?? $jiraWorkLog->authorName;
            if (null === $key || isset($excludeAuthorKeys[$key])) {
                return false;
            }

            $representatives[$key] ??= $jiraWorkLog;

            return true;
        };

        // The reader applies range + normalization; its returned records tell us which author
        // keys actually have an in-range worklog, so we skip authors with nothing to sync.
        // Discovery problems must not be silent: a truncated search or failed issue
        // fetch means some authors may be missing from this PO pass — log them.
        $onNotice = function (string $type, ?string $issueKey = null): void {
            $this->logger?->warning('PO sync-all author discovery notice', ['type' => $type, 'issue' => $issueKey]);
        };
        $records = $this->remoteWorklogReader->readForAuthor($api, $matchesAuthor, $jql, $from, $to, $onNotice);
        $survivingKeys = [];
        foreach ($records as $record) {
            $author = $record['author'];
            if (null !== $author) {
                $survivingKeys[$author] = true;
            }
        }

        $authors = [];
        foreach ($representatives as $key => $representative) {
            if (!isset($survivingKeys[$key])) {
                continue;
            }

            $author = $this->jiraAuthorMapper->find($representative, $ticketSystem) ?? $this->jiraAuthorMapper->createShadow($representative, $ticketSystem);
            $authors[spl_object_id($author)] = $author;
        }

        return array_values($authors);
    }

    /**
     * Build the target's read JQL and author predicate — self via currentUser(), a PO target
     * via the target's remote identity.
     *
     * @return array{string, callable(JiraWorkLog): bool}
     */
    private function buildRead(JiraOAuthApiService $api, User $targetUser, User $tokenOwner, TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($targetUser === $tokenOwner) {
            $myself = $api->getMyself();
            $jql = sprintf(
                'worklogAuthor = currentUser() AND worklogDate >= "%s" AND worklogDate <= "%s"',
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            );

            return [$jql, $myself->matchesWorklogAuthor(...)];
        }

        $identity = $this->targetIdentity($targetUser, $ticketSystem);
        $jql = sprintf(
            'worklogAuthor = "%s" AND worklogDate >= "%s" AND worklogDate <= "%s"',
            $identity->accountId ?? $identity->name ?? '',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );

        return [$jql, $identity->matchesWorklogAuthor(...)];
    }

    /**
     * The target's Jira identity for this ticket system — durable remote_account_id, username fallback.
     */
    private function targetIdentity(User $targetUser, TicketSystem $ticketSystem): JiraUserIdentity
    {
        $remoteAccountId = null;
        foreach ($targetUser->getUserTicketsystems() as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem() === $ticketSystem) {
                $remoteAccountId = $userTicketsystem->getRemoteAccountId();
                break;
            }
        }

        return new JiraUserIdentity(accountId: $remoteAccountId, name: $targetUser->getUsername());
    }

    /**
     * Reconcile the target's TT entries against their remote worklogs and execute every
     * matrix row under the run's token (ADR-023 §2, minus the feed).
     *
     * @param callable(JiraWorkLog): bool $matchesAuthor
     */
    private function runUserSync(SyncRunContext $context, User $targetUser, callable $matchesAuthor, string $jql, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        // A failed issue fetch, a worklog that could not be normalized or a capped issue search
        // can hide worklogs that still exist in Jira; their entries must then not be concluded
        // deleted (or moved) in Jira.
        $gaps = new RemoteReadGaps();
        $remoteByWorklogId = $this->remoteWorklogReader->readForAuthor(
            $context->api,
            $matchesAuthor,
            $jql,
            $from,
            $to,
            function (string $type, ?string $issueKey = null, ?Throwable $throwable = null, ?int $worklogId = null) use ($context, $gaps): void {
                $gaps->record($type, $worklogId);
                $this->onRemoteNotice($context->syncRun, $type, $issueKey, $throwable, $worklogId);
            },
        );

        $entries = $this->entryRepository->findJiraSyncCandidates($targetUser, $context->ticketSystem, $from, $to);
        $this->claimOwnedWorklogs($gaps, $entries, $context->ticketSystem);
        $absentWorklogIds = [];

        foreach ($entries as $entry) {
            $worklogId = $entry->getWorklogId();
            if (null !== $worklogId && $worklogId > 0 && isset($remoteByWorklogId[$worklogId])) {
                $record = $remoteByWorklogId[$worklogId];
                unset($remoteByWorklogId[$worklogId]);
                $worklog = $this->synthesizeWorklog($worklogId, $record);
                $this->reconcileAndExecute($context, $entry, $record['snapshot'], $worklog, $record['issueKey']);

                continue;
            }

            if (null !== $worklogId && $worklogId > 0) {
                $absentWorklogIds[] = $worklogId;

                continue;
            }

            // Never synced: create the worklog remotely under the token owner.
            $this->handlePush($context, $entry, (string) $entry->getTicket());
        }

        // Whatever remains on the remote side has no matching entry — pool it for move-detection
        // (delete-by-absence relink) and unattended import.
        $linkedEntries = $this->entryRepository->findByWorklogIdsAndTicketSystem(array_keys($remoteByWorklogId), $context->ticketSystem);
        foreach ($remoteByWorklogId as $worklogId => $record) {
            // A worklog that already belongs to a local entry outside the candidates (e.g.
            // agent walltime synced before ADR-025 §7 was enforced) is neither a move
            // target nor an import candidate.
            $linkedEntry = $linkedEntries[$worklogId] ?? null;
            if ($linkedEntry instanceof Entry) {
                $this->reportLinkedRemoteWorklog($context->syncRun, $linkedEntry, $worklogId, $record['issueKey']);

                continue;
            }

            $context->unmatchedRemote[$worklogId] = [
                'worklog' => $this->synthesizeWorklog($worklogId, $record),
                'snapshot' => $record['snapshot'],
                'issueKey' => $record['issueKey'],
            ];
        }

        $heldLookalikes = [];
        foreach ($absentWorklogIds as $worklogId) {
            $this->processDeletedWorklog($context, $worklogId, $gaps, $heldLookalikes);
        }

        // Lookalikes are held only under a run-wide gap, where no entry of the run may relink, so
        // none can have been taken by a relink; applying the hold after the loop keeps that true
        // even if the rules change. Reported, so a withheld Jira worklog never disappears silently.
        foreach ($heldLookalikes as $worklogId => $unverifiedEntries) {
            $candidate = $context->unmatchedRemote[$worklogId] ?? null;
            if (null === $candidate) {
                continue;
            }

            unset($context->unmatchedRemote[$worklogId]);
            $context->syncRun->incrementCounter('lookalike_held');
            $this->addItem(
                $context->syncRun,
                SyncItemKind::REMOTE_ONLY,
                issueKey: $candidate['issueKey'],
                remoteWorklogId: $worklogId,
                entry: $unverifiedEntries[0],
                author: $this->jiraAuthorMapper->remoteKey($candidate['worklog']),
                reason: 'held back from import: may be the move of an entry whose own worklog could not be verified in this run',
                payload: [
                    'remote' => $candidate['snapshot']->toArray(),
                    'updated' => $candidate['worklog']->updated,
                    'entries' => array_map(static fn (Entry $entry): ?int => $entry->getId(), $unverifiedEntries),
                ],
            );
        }

        $this->handleUnmatched($context, $targetUser);

        $this->entityManager->flush();

        // Ids exist only after the flush above (same post-flush id rule as import).
        foreach ($context->affectedDays as $affected) {
            $this->dayClassService->recalculate((int) $affected['user']->getId(), $affected['day']);
        }
    }

    /**
     * Tells the gaps which worklog ids local entries hold, so an unreadable worklog that sits
     * where an entry says it does stops blocking the run (RemoteReadGaps::claim()).
     *
     * @param list<Entry> $entries the run's candidates
     */
    private function claimOwnedWorklogs(RemoteReadGaps $gaps, array $entries, TicketSystem $ticketSystem): void
    {
        foreach ($entries as $entry) {
            $worklogId = $entry->getWorklogId();
            if (null !== $worklogId && $worklogId > 0) {
                $gaps->claim($worklogId);
            }
        }

        // Entries outside the synced window own worklogs too, and an unreadable one of theirs is
        // no more a move target than a candidate's: one lookup keeps it from blocking the run.
        $unclaimed = $gaps->unclaimedUnreadableWorklogIds();
        if ([] === $unclaimed) {
            return;
        }

        foreach (array_keys($this->entryRepository->findByWorklogIdsAndTicketSystem($unclaimed, $ticketSystem)) as $worklogId) {
            $gaps->claim($worklogId);
        }
    }

    /**
     * Rebuild a JiraWorkLog from a reader record so the shared handlers (which are worklog-centric)
     * can run without a second remote fetch.
     *
     * @param array{snapshot: WorklogSnapshot, updated: ?string, author: ?string, issueKey: string} $record
     */
    private function synthesizeWorklog(int $worklogId, array $record): JiraWorkLog
    {
        $snapshot = $record['snapshot'];

        return new JiraWorkLog(
            id: $worklogId,
            comment: $snapshot->comment,
            started: date('Y-m-d\TH:i:s.000O', $snapshot->startedTimestamp),
            timeSpentSeconds: $snapshot->durationMinutes * 60,
            updated: $record['updated'],
            authorAccountId: $record['author'],
            authorName: $record['author'],
        );
    }

    /**
     * Translate a reader notice into a sync-run report item.
     */
    private function onRemoteNotice(SyncRun $syncRun, string $type, ?string $issueKey, ?Throwable $throwable, ?int $worklogId): void
    {
        if ('truncated' === $type) {
            $this->addItem($syncRun, SyncItemKind::TRUNCATED, reason: 'issue search hit its result cap; remaining changes are picked up by the next run');

            return;
        }

        $syncRun->incrementCounter('errors');
        $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $worklogId, reason: substr($throwable?->getMessage() ?? '', 0, 255));
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

        $outcome = $this->worklogWriteService->push($context->api, $entry, $context->ticketSystem);
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
        $outcome = $this->worklogWriteService->push($context->api, $entry, $context->ticketSystem);
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
                $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, entry: $entry, reason: 'push skipped: entry has no pushable ticket or is agent walltime');
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

    /**
     * A linked entry whose remote worklog is absent from the rescanned window — a remote delete
     * (or a move: a delete+create pair with identical start and duration is a relink).
     *
     * @param RemoteReadGaps          $gaps           what the remote read could not see: where a gap may
     *                                                hide the worklog, nothing is deleted, parked or relinked
     * @param array<int, list<Entry>> $heldLookalikes collects, by worklog id, the remote worklogs that
     *                                                may be the move of entries left unverified
     */
    private function processDeletedWorklog(SyncRunContext $context, int $deletedWorklogId, RemoteReadGaps $gaps, array &$heldLookalikes): void
    {
        $entry = $this->entryRepository->findOneByWorklogIdAndTicketSystem($deletedWorklogId, $context->ticketSystem);
        if (!$entry instanceof Entry) {
            return; // never knew this worklog
        }

        $projection = $this->entryWorklogProjector->project($entry);
        $mayConclude = $gaps->allowsConclusionAbout($deletedWorklogId);

        // Move detection first: a delete+create pair with identical start and duration is a relink.
        foreach ($context->unmatchedRemote as $candidateWorklogId => $candidate) {
            if ($candidate['snapshot']->startedTimestamp !== $projection->startedTimestamp
                || $candidate['snapshot']->durationMinutes !== $projection->durationMinutes
            ) {
                continue;
            }

            if ($mayConclude) {
                $this->relink($context, $entry, $candidateWorklogId, $candidate);

                return;
            }

            // Only a run-wide gap can hide a real move: then the lookalike may be this entry's
            // worklog, moved where the read could not see, and importing it would duplicate the
            // entry. A worklog Jira returned but could not normalize, and that a local entry
            // claims, still sits where that entry says, so a lookalike of it is a separate
            // booking and stays importable.
            if ($gaps->hidesMoves()) {
                $heldLookalikes[$candidateWorklogId][] = $entry;
            }
        }

        if (!$mayConclude) {
            $context->syncRun->incrementCounter('absence_unverified');

            return;
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
        $this->addItem($context->syncRun, SyncItemKind::LOCAL_ONLY, issueKey: $entry->getTicket(), remoteWorklogId: $deletedWorklogId, entry: $entry, reason: 'remote worklog absent; local entry modified — parked');
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

        $this->addItem($context->syncRun, SyncItemKind::LOCAL_ONLY, issueKey: $entry->getTicket(), remoteWorklogId: $deletedWorklogId, entry: $entry, reason: 'remote worklog absent; local entry removed');
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
     * Imports (or reports) remote worklogs that matched no entry and no move. Attribution is
     * constrained to the target user so a PO run books colleagues' work to the right person.
     */
    private function handleUnmatched(SyncRunContext $context, User $targetUser): void
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

        $targetUsername = $targetUser->getUsername();
        $importRunContext = new ImportRunContext(
            syncRun: $context->syncRun,
            triggeredBy: $context->tokenOwner,
            ticketSystem: $context->ticketSystem,
            activity: $activity,
            targetUsernames: null !== $targetUsername ? [$targetUsername] : [],
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
