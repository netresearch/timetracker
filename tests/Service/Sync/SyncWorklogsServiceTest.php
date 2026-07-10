<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraIssueKeySearchResult;
use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Entity\WorklogSyncState;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\WorklogField;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Repository\EntryRepository;
use App\Repository\UserTicketsystemRepository;
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
use App\Service\Sync\RemoteWorklogReader;
use App\Service\Sync\SyncWorklogsService;
use App\Service\Sync\WorklogWriteService;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\PullResult;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

use function array_map;
use function array_values;

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
    private UserTicketsystemRepository&MockObject $userTicketsystemRepository;
    private RoleHierarchyInterface&MockObject $roleHierarchy;
    private SyncWorklogsService $service;
    private TicketSystem&MockObject $ticketSystem;
    private User $targetUser;
    private EntryWorklogProjector $projector;
    /** @var list<object> */
    private array $persisted = [];
    /** @var array<int, Entry> */
    private array $entriesByWorklogId = [];
    /** @var array<int, WorklogSyncState> */
    private array $statesByEntry = [];
    /** @var list<Entry> */
    private array $candidates = [];
    /** @var array<string, list<JiraWorkLog>> */
    private array $worklogsByIssue = [];
    /** @var list<string> */
    private array $issueKeys = [];

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
        $this->userTicketsystemRepository = $this->createMock(UserTicketsystemRepository::class);
        $this->roleHierarchy = $this->createMock(RoleHierarchyInterface::class);

        $apiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $apiFactory->method('create')->willReturn($this->api);

        $this->entityManager->method('persist')->willReturnCallback(
            function (object $object): void { $this->persisted[] = $object; },
        );
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturnCallback(
            fn (int $worklogId): ?Entry => $this->entriesByWorklogId[$worklogId] ?? null,
        );
        $this->entryRepository->method('findJiraSyncCandidates')->willReturnCallback(
            fn (): array => $this->candidates,
        );
        $this->syncStateRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?WorklogSyncState {
                $entry = $criteria['entry'] ?? null;

                return $entry instanceof Entry ? ($this->statesByEntry[spl_object_id($entry)] ?? null) : null;
            },
        );

        // The token owner behind the mocked api. Its worklogs are attributed to 'acc-x'.
        $this->api->method('getMyself')->willReturn(new JiraUserIdentity(accountId: 'acc-x'));
        $this->api->method('searchIssueKeysWithWorklogs')->willReturnCallback(
            fn (): JiraIssueKeySearchResult => new JiraIssueKeySearchResult($this->issueKeys, false),
        );
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            fn (string $issueKey): array => $this->worklogsByIssue[$issueKey] ?? [],
        );

        $this->targetUser = new User()->setUsername('target');
        $this->projector = new EntryWorklogProjector();
        $this->ticketSystem = $this->makeTicketSystem();

        $this->service = new SyncWorklogsService(
            $this->entityManager,
            $this->entryRepository,
            $this->syncStateRepository,
            $apiFactory,
            $this->projector,
            new RemoteWorklogReader(new RemoteWorklogNormalizer()),
            new ReconciliationService(),
            $this->worklogWriteService,
            $this->entryPullApplier,
            $this->importWorklogsService,
            $this->authorMapper,
            $this->dayClassService,
            $this->userTicketsystemRepository,
            $this->roleHierarchy,
            new MockClock('2026-07-09 12:00:00'),
        );
    }

    private function makeTicketSystem(?Activity $activity = null): TicketSystem&MockObject
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getSyncDefaultActivity')->willReturn($activity);

        return $ticketSystem;
    }

    /**
     * Register a linked TT entry (owner = target) as a sync candidate, keyed by worklog id.
     */
    private function linkedEntry(int $worklogId): Entry
    {
        $entry = new Entry()
            ->setUser($this->targetUser)
            ->setTicket('TIM-1')
            ->setDay('2026-06-10')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDescription('did things')
            ->setWorklogId($worklogId);
        $entry->setDuration(60);
        $this->entriesByWorklogId[$worklogId] = $entry;
        $this->candidates[] = $entry;

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

    /**
     * Publish a remote worklog on issue TIM-1, authored by the token owner ('acc-x').
     */
    private function remoteWorklog(WorklogSnapshot $snapshot, int $id, string $updated = 'U1', string $issueKey = 'TIM-1'): JiraWorkLog
    {
        $worklog = new JiraWorkLog(
            id: $id,
            comment: $snapshot->comment,
            started: date('Y-m-d\TH:i:s.000O', $snapshot->startedTimestamp),
            timeSpentSeconds: $snapshot->durationMinutes * 60,
            updated: $updated,
            authorAccountId: 'acc-x',
            authorName: 'target',
            issueId: '10001',
        );
        $this->publish($issueKey, $worklog);

        return $worklog;
    }

    private function publish(string $issueKey, JiraWorkLog $worklog): void
    {
        if (!isset($this->worklogsByIssue[$issueKey])) {
            $this->worklogsByIssue[$issueKey] = [];
            $this->issueKeys[] = $issueKey;
        }

        $this->worklogsByIssue[$issueKey][] = $worklog;
    }

    private function syncSelf(bool $dryRun = false): SyncRun
    {
        return $this->service->syncUser(
            $this->targetUser,
            $this->targetUser,
            $this->ticketSystem,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
            $dryRun,
        );
    }

    /**
     * @return list<SyncItemKind>
     */
    private function itemKinds(SyncRun $syncRun): array
    {
        return array_values(array_map(static fn ($item) => $item->getKind(), $syncRun->getItems()->toArray()));
    }

    public function testSyncUserRunTriggeredByTokenOwner(): void
    {
        $tokenOwner = new User()->setUsername('po');
        $this->targetUser->getUserTicketsystems()->add(
            new UserTicketsystem()->setTicketSystem($this->ticketSystem)->setRemoteAccountId('acc-x'),
        );

        $syncRun = $this->service->syncUser(
            $this->targetUser,
            $tokenOwner,
            $this->ticketSystem,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
        );

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame($tokenOwner, $syncRun->getTriggeredBy());
        self::assertSame('target', $syncRun->getScope()['target'] ?? null);
        self::assertSame('2026-06-01', $syncRun->getScope()['from'] ?? null);
    }

    public function testInSyncPairSeedsBaseWhenStateMissing(): void
    {
        $entry = $this->linkedEntry(11);
        $local = $this->projector->project($entry);
        $this->remoteWorklog($local, 11, 'U1');

        $syncRun = $this->syncSelf();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['in_sync'] ?? 0);
        $states = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof WorklogSyncState));
        self::assertCount(1, $states);
        self::assertSame($local->toArray(), $states[0]->getBasePayload());
        self::assertSame('U1', $states[0]->getBaseUpdatedAt());
        self::assertSame(WorklogSyncStatus::IN_SYNC, $states[0]->getStatus());
    }

    public function testLocalDirtyPushesUnderTokenOwner(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $entry->setDescription('changed locally');
        $this->stateFor($entry, $base);
        $this->remoteWorklog($base, 11, 'U1');

        $this->worklogWriteService->expects(self::once())->method('push')
            ->with(self::identicalTo($this->api), self::identicalTo($entry), self::identicalTo($this->ticketSystem))
            ->willReturn(WriteOutcome::WRITTEN);

        $syncRun = $this->syncSelf();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['pushed'] ?? 0);
    }

    public function testRemoteDirtyPullsAndRefreshesBase(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $state = $this->stateFor($entry, $base);
        $this->remoteWorklog(new WorklogSnapshot(
            issueKey: $base->issueKey,
            startedTimestamp: $base->startedTimestamp,
            durationMinutes: 90,
            comment: $base->comment,
        ), 11, 'U2');

        $this->entryPullApplier->expects(self::once())->method('apply')
            ->with(
                self::identicalTo($entry),
                self::callback(static fn (WorklogSnapshot $snapshot): bool => 90 === $snapshot->durationMinutes),
                [WorklogField::DURATION],
                self::identicalTo($this->ticketSystem),
            )
            ->willReturn(new PullResult(true, '', ['2026-06-10']));

        $syncRun = $this->syncSelf();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['pulled'] ?? 0);
        self::assertSame(90, $state->getBasePayload()['duration_minutes'] ?? null);
        self::assertSame('U2', $state->getBaseUpdatedAt());
        self::assertSame(WorklogSyncStatus::IN_SYNC, $state->getStatus());
    }

    public function testMergeAppliesRemoteThenPushes(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        // Local changed comment; remote changed duration — disjoint fields => merge.
        $entry->setDescription('local comment change');
        $this->stateFor($entry, $base);
        $this->remoteWorklog(new WorklogSnapshot(
            issueKey: $base->issueKey,
            startedTimestamp: $base->startedTimestamp,
            durationMinutes: 120,
            comment: $base->comment,
        ), 11, 'U2');

        $this->entryPullApplier->expects(self::once())->method('apply')
            ->willReturn(new PullResult(true, '', ['2026-06-10']));
        $this->worklogWriteService->expects(self::once())->method('push')
            ->with(self::identicalTo($this->api), self::identicalTo($entry), self::identicalTo($this->ticketSystem))
            ->willReturn(WriteOutcome::WRITTEN);

        $syncRun = $this->syncSelf();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['merged'] ?? 0);
    }

    public function testConflictParksWithRemotePayload(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $entry->setDescription('local change');
        $state = $this->stateFor($entry, $base);
        $this->remoteWorklog(new WorklogSnapshot(
            issueKey: $base->issueKey,
            startedTimestamp: $base->startedTimestamp,
            durationMinutes: 60,
            comment: '#0: no activity specified: remote change',
        ), 11, 'U2');

        $this->worklogWriteService->expects(self::never())->method('push');
        $this->entryPullApplier->expects(self::never())->method('apply');

        $syncRun = $this->syncSelf();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['conflicts'] ?? 0);
        self::assertSame(WorklogSyncStatus::CONFLICT, $state->getStatus());
        self::assertNotNull($state->getConflictRemotePayload());
        self::assertSame('U2', $state->getConflictRemotePayload()['updated'] ?? null);
        self::assertContains(SyncItemKind::CONFLICT, $this->itemKinds($syncRun));
    }

    public function testAbsentRemoteRemovesCleanEntry(): void
    {
        $entry = $this->linkedEntry(11);
        $this->stateFor($entry, $this->projector->project($entry));
        // No remote worklog published => the linked worklog is absent.

        $this->entityManager->expects(self::once())->method('remove')->with(self::identicalTo($entry));

        $syncRun = $this->syncSelf();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['deleted_local'] ?? 0);
        self::assertContains(SyncItemKind::LOCAL_ONLY, $this->itemKinds($syncRun));
    }

    public function testAbsentRemoteParksDirtyEntryAsOrphaned(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $entry->setDescription('local change');
        $state = $this->stateFor($entry, $base);

        $this->entityManager->expects(self::never())->method('remove');

        $syncRun = $this->syncSelf();

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
        // The old worklog (11) is gone; a new one (22) with the same start+duration appears.
        $this->remoteWorklog($local, 22, 'U5');

        $this->entityManager->expects(self::never())->method('remove');
        $this->importWorklogsService->expects(self::never())->method('processWorklog');

        $syncRun = $this->syncSelf();

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
        $this->ticketSystem = $this->makeTicketSystem($activity);
        $worklog = $this->remoteWorklog(new WorklogSnapshot(
            issueKey: 'TIM-1',
            startedTimestamp: new DateTimeImmutable('2026-06-10 09:00:00')->getTimestamp(),
            durationMinutes: 60,
            comment: 'jira side',
        ), 33, 'U1');

        $this->importWorklogsService->expects(self::once())->method('processWorklog')
            ->with(
                self::isInstanceOf(ImportRunContext::class),
                'TIM-1',
                self::callback(static fn (JiraWorkLog $candidate): bool => 33 === $candidate->id),
            );

        $syncRun = $this->service->syncUser(
            $this->targetUser,
            $this->targetUser,
            $this->ticketSystem,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
        );

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        unset($worklog);
    }

    public function testUnmatchedRemoteReportedWhenNoActivity(): void
    {
        $this->remoteWorklog(new WorklogSnapshot(
            issueKey: 'TIM-1',
            startedTimestamp: new DateTimeImmutable('2026-06-10 09:00:00')->getTimestamp(),
            durationMinutes: 60,
            comment: 'jira side',
        ), 33, 'U1');

        $this->importWorklogsService->expects(self::never())->method('processWorklog');

        $syncRun = $this->syncSelf();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['remote_only'] ?? 0);
        self::assertContains(SyncItemKind::REMOTE_ONLY, $this->itemKinds($syncRun));
    }

    public function testDryRunNeverWrites(): void
    {
        $entry = $this->linkedEntry(11);
        $base = $this->projector->project($entry);
        $entry->setDescription('changed locally');
        $this->stateFor($entry, $base);
        $this->remoteWorklog($base, 11, 'U1');

        $deletedEntry = $this->linkedEntry(12);
        $this->stateFor($deletedEntry, $this->projector->project($deletedEntry));
        // worklog 12 is absent on the remote => a would-be clean delete.

        $this->worklogWriteService->expects(self::never())->method('push');
        $this->entryPullApplier->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('remove');
        $this->importWorklogsService->expects(self::never())->method('processWorklog');

        $syncRun = $this->syncSelf(dryRun: true);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['would_push'] ?? 0);
        self::assertSame(1, $syncRun->getCounters()['would_delete_local'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof WorklogSyncState));
    }

    public function testSyncTicketSystemSelfSyncsEnabledUsers(): void
    {
        $userA = new User()->setUsername('alice');
        $userB = new User()->setUsername('bob');
        $this->userTicketsystemRepository->method('findSyncEnabled')->willReturn([
            new UserTicketsystem()->setUser($userA)->setTicketSystem($this->ticketSystem),
            new UserTicketsystem()->setUser($userB)->setTicketSystem($this->ticketSystem),
        ]);
        $this->userTicketsystemRepository->method('findSyncAllOwners')->willReturn([]);

        $runs = $this->service->syncTicketSystem(
            $this->ticketSystem,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
        );

        self::assertCount(2, $runs);
        $targets = array_map(static fn (SyncRun $run): mixed => $run->getScope()['target'] ?? null, $runs);
        self::assertSame(['alice', 'bob'], $targets);
        foreach ($runs as $run) {
            self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        }
    }

    /** One remote worklog authored by 'colleague-acc' on TIM-9 — the PO-coverage fixture. */
    private function publishColleagueWorklog(): void
    {
        $this->publish('TIM-9', new JiraWorkLog(
            id: 77,
            comment: 'work',
            started: '2026-06-10T09:00:00.000+0200',
            timeSpentSeconds: 3600,
            updated: 'U1',
            authorAccountId: 'colleague-acc',
            authorName: 'colleague',
            issueId: '10009',
        ));
    }

    public function testSyncTicketSystemPoCoversNonEnabledAuthors(): void
    {
        $po = new User()->setUsername('po');
        $covered = new User()->setUsername('colleague');
        $this->userTicketsystemRepository->method('findSyncEnabled')->willReturn([]);
        $this->userTicketsystemRepository->method('findSyncAllOwners')->willReturn([
            new UserTicketsystem()->setUser($po)->setTicketSystem($this->ticketSystem),
        ]);
        $this->roleHierarchy->method('getReachableRoleNames')->willReturn(['ROLE_PL', 'ROLE_USER']);

        $this->publishColleagueWorklog();
        $this->authorMapper->method('find')->willReturn($covered);

        $runs = $this->service->syncTicketSystem(
            $this->ticketSystem,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
        );

        self::assertCount(1, $runs);
        self::assertSame('colleague', $runs[0]->getScope()['target'] ?? null);
        self::assertSame($po, $runs[0]->getTriggeredBy());
    }

    public function testOverlappingSyncAllPosCoverAnAuthorOnlyOnce(): void
    {
        $poOne = new User()->setUsername('po.one');
        $poTwo = new User()->setUsername('po.two');
        $covered = new User()->setUsername('colleague');
        $this->userTicketsystemRepository->method('findSyncEnabled')->willReturn([]);
        $this->userTicketsystemRepository->method('findSyncAllOwners')->willReturn([
            new UserTicketsystem()->setUser($poOne)->setTicketSystem($this->ticketSystem),
            new UserTicketsystem()->setUser($poTwo)->setTicketSystem($this->ticketSystem),
        ]);
        $this->roleHierarchy->method('getReachableRoleNames')->willReturn(['ROLE_PL', 'ROLE_USER']);

        // Both POs' broad reads see the same worklog by the same author.
        $this->publishColleagueWorklog();
        $this->authorMapper->method('find')->willReturn($covered);

        $runs = $this->service->syncTicketSystem(
            $this->ticketSystem,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
        );

        // The first PO takes responsibility; the second must not produce a duplicate run.
        self::assertCount(1, $runs);
        self::assertSame('po.one', $runs[0]->getTriggeredBy()?->getUsername());
        self::assertSame('colleague', $runs[0]->getScope()['target'] ?? null);
    }

    public function testSyncTicketSystemPoSkipsSelfEnabledAuthors(): void
    {
        $po = new User()->setUsername('po');
        $selfEnabledUser = new User()->setUsername('selfie');
        $this->userTicketsystemRepository->method('findSyncEnabled')->willReturn([
            new UserTicketsystem()->setUser($selfEnabledUser)->setTicketSystem($this->ticketSystem)->setRemoteAccountId('acc-x'),
        ]);
        $this->userTicketsystemRepository->method('findSyncAllOwners')->willReturn([
            new UserTicketsystem()->setUser($po)->setTicketSystem($this->ticketSystem),
        ]);
        $this->roleHierarchy->method('getReachableRoleNames')->willReturn(['ROLE_ADMIN', 'ROLE_USER']);

        // The only worklog the PO can see is authored by the self-enabled user ('acc-x').
        $this->publish('TIM-1', new JiraWorkLog(
            id: 88,
            comment: 'own work',
            started: '2026-06-10T09:00:00.000+0200',
            timeSpentSeconds: 3600,
            updated: 'U1',
            authorAccountId: 'acc-x',
            authorName: 'selfie',
            issueId: '10001',
        ));
        // find() must never be reached for the excluded author.
        $this->authorMapper->expects(self::never())->method('find');
        $this->authorMapper->expects(self::never())->method('createShadow');

        $runs = $this->service->syncTicketSystem(
            $this->ticketSystem,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
        );

        // One run for the self-enabled user (pass 1); the PO adds nothing (pass 2 excluded it).
        self::assertCount(1, $runs);
        self::assertSame('selfie', $runs[0]->getScope()['target'] ?? null);
    }

    public function testNonPoSyncAllIgnored(): void
    {
        $notAPo = new User()->setUsername('plain');
        $this->userTicketsystemRepository->method('findSyncEnabled')->willReturn([]);
        $this->userTicketsystemRepository->method('findSyncAllOwners')->willReturn([
            new UserTicketsystem()->setUser($notAPo)->setTicketSystem($this->ticketSystem),
        ]);
        $this->roleHierarchy->method('getReachableRoleNames')->willReturn(['ROLE_USER']);
        $this->authorMapper->expects(self::never())->method('find');

        $runs = $this->service->syncTicketSystem(
            $this->ticketSystem,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
        );

        self::assertSame([], $runs);
    }
}
