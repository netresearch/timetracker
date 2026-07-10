<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\DTO\Jira\JiraWorklogFeedPage;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\WorklogField;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Repository\EntryRepository;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\EntryPullApplier;
use App\Service\Sync\EntryWorklogProjector;
use App\Service\Sync\ImportRunContext;
use App\Service\Sync\ImportWorklogsService;
use App\Service\Sync\JiraAuthorMapper;
use App\Service\Sync\ReconciliationService;
use App\Service\Sync\RemoteWorklogNormalizer;
use App\Service\Sync\SyncWorklogsService;
use App\Service\Sync\WorklogWriteService;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\PullResult;
use App\ValueObject\Sync\WorklogSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SyncWorklogsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class SyncWorklogsServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private EntryRepository&MockObject $entryRepository;
    private WorklogSyncStateRepository&MockObject $syncStateRepository;
    private JiraOAuthApiService&MockObject $api;
    private WorklogWriteService&MockObject $worklogWriteService;
    private EntryPullApplier&MockObject $entryPullApplier;
    private ImportWorklogsService&MockObject $importWorklogsService;
    private DayClassService&MockObject $dayClassService;
    private JiraAuthorMapper&MockObject $authorMapper;
    private SyncWorklogsService $service;
    private TicketSystem&MockObject $ticketSystem;
    private User $syncUser;
    private EntryWorklogProjector $projector;
    /** @var list<object> */
    private array $persisted = [];
    /** @var array<int, Entry> */
    private array $entriesByWorklogId = [];
    /** @var array<int, WorklogSyncState> */
    private array $statesByEntry = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entryRepository = $this->createMock(EntryRepository::class);
        $this->syncStateRepository = $this->createMock(WorklogSyncStateRepository::class);
        $this->api = $this->createMock(JiraOAuthApiService::class);
        $this->worklogWriteService = $this->createMock(WorklogWriteService::class);
        $this->entryPullApplier = $this->createMock(EntryPullApplier::class);
        $this->importWorklogsService = $this->createMock(ImportWorklogsService::class);
        $this->dayClassService = $this->createMock(DayClassService::class);
        $this->authorMapper = $this->createMock(JiraAuthorMapper::class);

        $apiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $apiFactory->method('create')->willReturn($this->api);

        $this->entityManager->method('persist')->willReturnCallback(
            function (object $object): void { $this->persisted[] = $object; },
        );
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturnCallback(
            fn (int $worklogId): ?Entry => $this->entriesByWorklogId[$worklogId] ?? null,
        );
        $this->syncStateRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?WorklogSyncState {
                $entry = $criteria['entry'] ?? null;

                return $entry instanceof Entry ? ($this->statesByEntry[spl_object_id($entry)] ?? null) : null;
            },
        );

        $this->syncUser = new User()->setUsername('syncbot');
        $this->projector = new EntryWorklogProjector();
        $this->ticketSystem = $this->makeTicketSystem($this->syncUser, 1000);

        $this->service = new SyncWorklogsService(
            $this->entityManager,
            $this->entryRepository,
            $this->syncStateRepository,
            $apiFactory,
            $this->projector,
            new RemoteWorklogNormalizer(),
            new ReconciliationService(),
            $this->worklogWriteService,
            $this->entryPullApplier,
            $this->importWorklogsService,
            $this->authorMapper,
            $this->dayClassService,
            new MockClock('2026-07-09 12:00:00'),
        );
    }

    private function makeTicketSystem(?User $syncUser, ?int $cursor, ?Activity $activity = null): TicketSystem&MockObject
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getSyncUser')->willReturn($syncUser);
        $ticketSystem->method('getWorklogSyncCursor')->willReturn($cursor);
        $ticketSystem->method('getSyncDefaultActivity')->willReturn($activity);

        return $ticketSystem;
    }

    private function linkedEntry(int $worklogId): Entry
    {
        $entry = new Entry()
            ->setUser(new User()->setUsername('owner'))
            ->setTicket('TIM-1')
            ->setDay('2026-06-10')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDescription('did things')
            ->setWorklogId($worklogId);
        $entry->setDuration(60);
        $this->entriesByWorklogId[$worklogId] = $entry;

        return $entry;
    }

    private function stateFor(Entry $entry, WorklogSnapshot $base, string $baseUpdatedAt = 'U1'): WorklogSyncState
    {
        $state = new WorklogSyncState()
            ->setEntry($entry)
            ->setTicketSystem($this->ticketSystem)
            ->setStatus(WorklogSyncStatus::IN_SYNC)
            ->setBasePayload($base->toArray())
            ->setBaseUpdatedAt($baseUpdatedAt);
        $this->statesByEntry[spl_object_id($entry)] = $state;

        return $state;
    }

    private function remoteWorklog(WorklogSnapshot $snapshot, int $id, string $updated = 'U1'): JiraWorkLog
    {
        return new JiraWorkLog(
            id: $id,
            comment: $snapshot->comment,
            started: date('Y-m-d\TH:i:s.000O', $snapshot->startedTimestamp),
            timeSpentSeconds: $snapshot->durationMinutes * 60,
            updated: $updated,
            authorAccountId: 'acc-x',
            authorName: 'remoteuser',
            issueId: '10001',
        );
    }

    /**
     * @param list<int>         $updatedIds
     * @param list<int>         $deletedIds
     * @param list<JiraWorkLog> $worklogs
     */
    private function stubFeeds(array $updatedIds, array $deletedIds, array $worklogs): void
    {
        $this->api->method('getWorklogsUpdatedSince')->willReturn(new JiraWorklogFeedPage($updatedIds, 2000, true));
        $this->api->method('getDeletedWorklogsSince')->willReturn(new JiraWorklogFeedPage($deletedIds, 2000, true));
        $this->api->method('getWorklogsByIds')->willReturn($worklogs);
        $this->api->method('getIssueKeyById')->willReturn('TIM-1');
    }

    /**
     * @return list<SyncItemKind>
     */
    private function itemKinds(SyncRun $syncRun): array
    {
        return array_values(array_map(static fn ($item) => $item->getKind(), $syncRun->getItems()->toArray()));
    }

    public function testNoSyncUserFailsRun(): void
    {
        $ticketSystem = $this->makeTicketSystem(null, 1000);

        $syncRun = $this->service->sync($ticketSystem);

        self::assertSame(SyncRunStatus::FAILED, $syncRun->getStatus());
        $items = $syncRun->getItems()->toArray();
        self::assertCount(1, $items);
        self::assertStringContainsString('No sync user', $items[0]->getReason());
    }

    public function testNoCursorAndNoOverrideFailsRun(): void
    {
        $ticketSystem = $this->makeTicketSystem($this->syncUser, null);

        $syncRun = $this->service->sync($ticketSystem);

        self::assertSame(SyncRunStatus::FAILED, $syncRun->getStatus());
        $items = $syncRun->getItems()->toArray();
        self::assertCount(1, $items);
        self::assertStringContainsString('No cursor yet', $items[0]->getReason());
    }

    public function testInSyncPairSeedsBaseWhenStateMissing(): void
    {
        $entry = $this->linkedEntry(11);
        $local = $this->projector->project($entry);
        $this->stubFeeds([11], [], [$this->remoteWorklog($local, 11, 'U1')]);

        $syncRun = $this->service->sync($this->ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['in_sync'] ?? 0);
        $states = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof WorklogSyncState));
        self::assertCount(1, $states);
        self::assertSame($local->toArray(), $states[0]->getBasePayload());
        self::assertSame('U1', $states[0]->getBaseUpdatedAt());
        self::assertSame(WorklogSyncStatus::IN_SYNC, $states[0]->getStatus());
    }

    public function testLocalDirtyPushesViaLeaseService(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $entry->setDescription('changed locally');
        $this->stateFor($entry, $base);
        $this->stubFeeds([11], [], [$this->remoteWorklog($base, 11, 'U1')]);

        $this->worklogWriteService->expects(self::once())->method('push')
            ->with(self::identicalTo($this->api), self::identicalTo($entry), self::identicalTo($this->ticketSystem))
            ->willReturn(WriteOutcome::WRITTEN);

        $syncRun = $this->service->sync($this->ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['pushed'] ?? 0);
    }

    public function testRemoteDirtyPullsAndRefreshesBase(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $state = $this->stateFor($entry, $base);
        $remoteWorklog = new JiraWorkLog(
            id: 11,
            comment: $base->comment,
            started: date('Y-m-d\TH:i:s.000O', $base->startedTimestamp),
            timeSpentSeconds: 90 * 60,
            updated: 'U2',
            authorAccountId: 'acc-x',
            issueId: '10001',
        );
        $this->stubFeeds([11], [], [$remoteWorklog]);

        $this->entryPullApplier->expects(self::once())->method('apply')
            ->with(
                self::identicalTo($entry),
                self::callback(static fn (WorklogSnapshot $snapshot): bool => 90 === $snapshot->durationMinutes),
                [WorklogField::DURATION],
                self::identicalTo($this->ticketSystem),
            )
            ->willReturn(new PullResult(true, '', ['2026-06-10']));

        $syncRun = $this->service->sync($this->ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['pulled'] ?? 0);
        self::assertSame(90, $state->getBasePayload()['duration_minutes'] ?? null);
        self::assertSame('U2', $state->getBaseUpdatedAt());
        self::assertSame(WorklogSyncStatus::IN_SYNC, $state->getStatus());
    }

    public function testConflictParksWithRemotePayload(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $entry->setDescription('local change');
        $state = $this->stateFor($entry, $base);
        $remoteWorklog = new JiraWorkLog(
            id: 11,
            comment: '#0: no activity specified: remote change',
            started: date('Y-m-d\TH:i:s.000O', $base->startedTimestamp),
            timeSpentSeconds: 3600,
            updated: 'U2',
            authorAccountId: 'acc-x',
            issueId: '10001',
        );
        $this->stubFeeds([11], [], [$remoteWorklog]);

        $this->worklogWriteService->expects(self::never())->method('push');
        $this->entryPullApplier->expects(self::never())->method('apply');

        $syncRun = $this->service->sync($this->ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['conflicts'] ?? 0);
        self::assertSame(WorklogSyncStatus::CONFLICT, $state->getStatus());
        self::assertNotNull($state->getConflictRemotePayload());
        self::assertSame('U2', $state->getConflictRemotePayload()['updated'] ?? null);
        self::assertContains(SyncItemKind::CONFLICT, $this->itemKinds($syncRun));
    }

    public function testDeletedFeedRemovesCleanEntry(): void
    {
        $entry = $this->linkedEntry(11);
        $this->stateFor($entry, $this->projector->project($entry));
        $this->stubFeeds([], [11], []);

        $this->entityManager->expects(self::once())->method('remove')->with(self::identicalTo($entry));

        $syncRun = $this->service->sync($this->ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['deleted_local'] ?? 0);
        self::assertContains(SyncItemKind::LOCAL_ONLY, $this->itemKinds($syncRun));
    }

    public function testDeletedFeedParksDirtyEntryAsOrphaned(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $entry->setDescription('local change');
        $state = $this->stateFor($entry, $base);
        $this->stubFeeds([], [11], []);

        $this->entityManager->expects(self::never())->method('remove');

        $syncRun = $this->service->sync($this->ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['orphaned'] ?? 0);
        self::assertSame(WorklogSyncStatus::ORPHANED, $state->getStatus());
        self::assertContains(SyncItemKind::LOCAL_ONLY, $this->itemKinds($syncRun));
    }

    public function testMoveRelinksDeletePlusCreatePair(): void
    {
        $entry = $this->linkedEntry(11);
        $local = $this->projector->project($entry);
        $state = $this->stateFor($entry, $local);
        $this->stubFeeds([22], [11], [$this->remoteWorklog($local, 22, 'U5')]);

        $this->entityManager->expects(self::never())->method('remove');
        $this->importWorklogsService->expects(self::never())->method('processWorklog');

        $syncRun = $this->service->sync($this->ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(22, $entry->getWorklogId());
        self::assertSame(1, $syncRun->getCounters()['relinked'] ?? 0);
        self::assertSame('U5', $state->getBaseUpdatedAt());
        self::assertSame($local->toArray(), $state->getBasePayload());
        self::assertSame(0, $syncRun->getCounters()['remote_only'] ?? 0);
    }

    public function testUnmatchedRemoteImportedWhenActivityConfigured(): void
    {
        $activity = self::createStub(Activity::class);
        $ticketSystem = $this->makeTicketSystem($this->syncUser, 1000, $activity);
        $worklog = new JiraWorkLog(
            id: 33,
            comment: 'jira side',
            started: '2026-06-10T09:00:00.000+0200',
            timeSpentSeconds: 3600,
            updated: 'U1',
            authorAccountId: 'acc-x',
            issueId: '10001',
        );
        $this->stubFeeds([33], [], [$worklog]);

        $this->importWorklogsService->expects(self::once())->method('processWorklog')
            ->with(self::isInstanceOf(ImportRunContext::class), 'TIM-1', self::identicalTo($worklog));

        $syncRun = $this->service->sync($ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
    }

    public function testUnmatchedRemoteReportedWhenNoActivity(): void
    {
        $worklog = new JiraWorkLog(
            id: 33,
            comment: 'jira side',
            started: '2026-06-10T09:00:00.000+0200',
            timeSpentSeconds: 3600,
            updated: 'U1',
            authorAccountId: 'acc-x',
            issueId: '10001',
        );
        $this->stubFeeds([33], [], [$worklog]);

        $this->importWorklogsService->expects(self::never())->method('processWorklog');

        $syncRun = $this->service->sync($this->ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['remote_only'] ?? 0);
        self::assertContains(SyncItemKind::REMOTE_ONLY, $this->itemKinds($syncRun));
    }

    public function testCursorAdvancesOnlyOnRealCompletedRun(): void
    {
        $this->stubFeeds([], [], []);

        $dryTicketSystem = $this->makeTicketSystem($this->syncUser, 1000);
        $dryTicketSystem->expects(self::never())->method('setWorklogSyncCursor');
        $dryRun = $this->service->sync($dryTicketSystem, null, true);
        self::assertSame(SyncRunStatus::COMPLETED, $dryRun->getStatus());

        $realTicketSystem = $this->makeTicketSystem($this->syncUser, 1000);
        $realTicketSystem->expects(self::once())->method('setWorklogSyncCursor')->with(2000);
        $realRun = $this->service->sync($realTicketSystem);
        self::assertSame(SyncRunStatus::COMPLETED, $realRun->getStatus());
    }

    public function testDryRunNeverWrites(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $entry->setDescription('changed locally');
        $this->stateFor($entry, $base);

        $deletedEntry = $this->linkedEntry(12);
        $this->stateFor($deletedEntry, $this->projector->project($deletedEntry));

        $unmatched = new JiraWorkLog(
            id: 33,
            comment: 'jira side',
            started: '2026-06-12T09:00:00.000+0200',
            timeSpentSeconds: 600,
            updated: 'U1',
            authorAccountId: 'acc-x',
            issueId: '10002',
        );
        $this->stubFeeds([11, 33], [12], [$this->remoteWorklog($base, 11, 'U1'), $unmatched]);

        $this->worklogWriteService->expects(self::never())->method('push');
        $this->entryPullApplier->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('remove');
        $this->importWorklogsService->expects(self::never())->method('processWorklog');

        $syncRun = $this->service->sync($this->ticketSystem, null, true);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['would_push'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof WorklogSyncState));
    }
}
