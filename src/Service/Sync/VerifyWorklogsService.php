<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogField;
use App\Repository\EntryRepository;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_map;
use function sprintf;
use function substr;

/**
 * ADR-023 verify: the reconciliation engine with all writes disabled. Reads TT and Jira,
 * writes ONLY a SyncRun report — never entries, never sync state, never Jira.
 */
class VerifyWorklogsService
{
    /** @var array<string, string> SyncAction value => counter key */
    private const array ACTION_COUNTERS = [
        'none' => 'in_sync',
        'push' => 'local_dirty',
        'pull' => 'remote_dirty',
        'merge' => 'mergeable',
        'conflict' => 'conflicts',
        'diverged' => 'diverged',
        'remote_missing' => 'local_only',
    ];

    /** @var array<string, SyncItemKind> SyncAction value => item kind (actions that yield items) */
    private const array ACTION_ITEM_KINDS = [
        'push' => SyncItemKind::LOCAL_DIRTY,
        'pull' => SyncItemKind::REMOTE_DIRTY,
        'merge' => SyncItemKind::MERGEABLE,
        'conflict' => SyncItemKind::CONFLICT,
        'diverged' => SyncItemKind::DIVERGED,
        'remote_missing' => SyncItemKind::LOCAL_ONLY,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
        private readonly WorklogSyncStateRepository $worklogSyncStateRepository,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly EntryWorklogProjector $entryWorklogProjector,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly ReconciliationService $reconciliationService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function verify(User $user, TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to): SyncRun
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setTicketSystem($ticketSystem)
            ->setTriggeredBy($user)
            ->setScope(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'dry_run' => true])
            ->setCounters([])
            ->setStartedAt(DateTimeImmutable::createFromInterface($this->clock->now()));

        $this->entityManager->persist($syncRun);

        try {
            $this->run($syncRun, $user, $ticketSystem, $from, $to);
            $syncRun->setStatus(SyncRunStatus::COMPLETED);
        } catch (Throwable $throwable) {
            $syncRun->setStatus(SyncRunStatus::FAILED);
            $this->addItem($syncRun, SyncItemKind::ERROR, reason: substr($throwable->getMessage(), 0, 255));
        }

        $syncRun->setFinishedAt(DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();

        return $syncRun;
    }

    private function run(SyncRun $syncRun, User $user, TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $api = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
        $myself = $api->getMyself();

        // --- Remote side: worklogs authored by this user in range, keyed by worklog id.
        $jql = sprintf(
            'worklogAuthor = currentUser() AND worklogDate >= "%s" AND worklogDate <= "%s"',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );
        $searchResult = $api->searchIssueKeysWithWorklogs($jql);
        if ($searchResult->truncated) {
            $this->addItem($syncRun, SyncItemKind::TRUNCATED, reason: 'issue search hit its result cap; report may be incomplete');
        }

        $rangeFrom = $from->setTime(0, 0)->getTimestamp();
        $rangeTo = $to->setTime(23, 59, 59)->getTimestamp();

        /** @var array<int, array{snapshot: WorklogSnapshot, updated: ?string, author: ?string}> $remoteByWorklogId */
        $remoteByWorklogId = [];
        foreach ($searchResult->keys as $issueKey) {
            try {
                $issueWorklogs = $api->getIssueWorklogs($issueKey);
            } catch (Throwable $throwable) {
                $syncRun->incrementCounter('errors');
                $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, reason: substr('worklog fetch failed: ' . $throwable->getMessage(), 0, 255));
                continue;
            }

            foreach ($issueWorklogs as $jiraWorkLog) {
                if (null === $jiraWorkLog->id) {
                    continue;
                }
                if (!$myself->matchesWorklogAuthor($jiraWorkLog)) {
                    continue;
                }
                try {
                    $snapshot = $this->remoteWorklogNormalizer->normalize($jiraWorkLog, $issueKey);
                } catch (InvalidArgumentException $exception) {
                    $syncRun->incrementCounter('errors');
                    $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: substr($exception->getMessage(), 0, 255));
                    continue;
                }
                if ($snapshot->startedTimestamp < $rangeFrom) {
                    continue;
                }
                if ($snapshot->startedTimestamp > $rangeTo) {
                    continue;
                }

                $remoteByWorklogId[$jiraWorkLog->id] = [
                    'snapshot' => $snapshot,
                    'updated' => $jiraWorkLog->updated,
                    'author' => $jiraWorkLog->authorAccountId ?? $jiraWorkLog->authorName,
                ];
            }
        }

        // --- Local side.
        $entries = $this->entryRepository->findJiraSyncCandidates($user, $ticketSystem, $from, $to);
        $entryIds = array_map(static fn (Entry $entry): int => (int) $entry->getId(), $entries);
        $syncStates = $this->worklogSyncStateRepository->findByEntryIds($entryIds);

        foreach ($entries as $entry) {
            $worklogId = $entry->getWorklogId();
            if (null === $worklogId || $worklogId <= 0) {
                $syncRun->incrementCounter('never_synced');
                $this->addItem($syncRun, SyncItemKind::NEVER_SYNCED, issueKey: $entry->getTicket(), entry: $entry, reason: 'entry has no linked Jira worklog');
                continue;
            }

            $base = null;
            $syncState = $syncStates[(int) $entry->getId()] ?? null;
            if (null !== $syncState) {
                $base = WorklogSnapshot::fromArray($syncState->getBasePayload());
            }

            $local = $this->entryWorklogProjector->project($entry);
            $remote = null;
            if (isset($remoteByWorklogId[$worklogId])) {
                $remote = $remoteByWorklogId[$worklogId]['snapshot'];
                unset($remoteByWorklogId[$worklogId]);
            }

            $decision = $this->reconciliationService->reconcile($base, $local, $remote);
            $syncRun->incrementCounter(self::ACTION_COUNTERS[$decision->action->value] ?? 'errors');

            $itemKind = self::ACTION_ITEM_KINDS[$decision->action->value] ?? null;
            if ($itemKind instanceof SyncItemKind) {
                $this->addItem(
                    $syncRun,
                    $itemKind,
                    issueKey: $entry->getTicket(),
                    remoteWorklogId: $worklogId,
                    entry: $entry,
                    reason: $decision->reason,
                    payload: [
                        'fields' => array_map(static fn (WorklogField $field) => $field->value, $decision->fields),
                        'local' => $local->toArray(),
                        'remote' => $remote?->toArray(),
                    ],
                );
            }
        }

        // --- Whatever remains on the remote side has no matching entry.
        foreach ($remoteByWorklogId as $worklogId => $remoteData) {
            $syncRun->incrementCounter('remote_only');
            $this->addItem(
                $syncRun,
                SyncItemKind::REMOTE_ONLY,
                issueKey: $remoteData['snapshot']->issueKey,
                remoteWorklogId: $worklogId,
                author: $remoteData['author'],
                reason: 'Jira worklog has no matching entry (import candidate)',
                payload: ['remote' => $remoteData['snapshot']->toArray(), 'updated' => $remoteData['updated']],
            );
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function addItem(
        SyncRun $syncRun,
        SyncItemKind $kind,
        ?string $issueKey = null,
        ?int $remoteWorklogId = null,
        ?Entry $entry = null,
        ?string $author = null,
        string $reason = '',
        ?array $payload = null,
    ): void {
        $syncRun->addItem(
            new SyncRunItem()
                ->setKind($kind)
                ->setIssueKey($issueKey)
                ->setRemoteWorklogId($remoteWorklogId)
                ->setEntry($entry)
                ->setAuthor($author)
                ->setReason($reason)
                ->setPayload($payload)
                ->setCreatedAt(DateTimeImmutable::createFromInterface($this->clock->now())),
        );
    }
}
