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
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Repository\EntryRepository;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\EntryWorklogProjector;
use App\Service\Sync\ReconciliationService;
use App\Service\Sync\RemoteWorklogNormalizer;
use App\Service\Sync\VerifyWorklogsService;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

#[CoversClass(VerifyWorklogsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class VerifyWorklogsServiceTest extends TestCase
{
    private EntryRepository&MockObject $entryRepository;

    private WorklogSyncStateRepository&MockObject $syncStateRepository;

    private JiraOAuthApiFactory&MockObject $apiFactory;

    private JiraOAuthApiService&MockObject $api;

    private EntityManagerInterface&MockObject $entityManager;

    private VerifyWorklogsService $service;

    private User $user;

    private TicketSystem $ticketSystem;

    protected function setUp(): void
    {
        $this->entryRepository = $this->createMock(EntryRepository::class);
        $this->syncStateRepository = $this->createMock(WorklogSyncStateRepository::class);
        $this->apiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $this->api = $this->createMock(JiraOAuthApiService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->apiFactory->method('create')->willReturn($this->api);
        $this->user = self::createStub(User::class);
        $this->ticketSystem = self::createStub(TicketSystem::class);

        $this->service = new VerifyWorklogsService(
            $this->entityManager,
            $this->entryRepository,
            $this->syncStateRepository,
            $this->apiFactory,
            new EntryWorklogProjector(),
            new RemoteWorklogNormalizer(),
            new ReconciliationService(),
            new MockClock('2026-07-09 12:00:00'),
        );
    }

    /**
     * Entry #42, Development, "fixed it", 2026-06-15 09:00, 60 min, linked worklog 1001.
     */
    private function linkedEntry(): Entry
    {
        $activity = self::createStub(Activity::class);
        $activity->method('getName')->willReturn('Development');

        $entry = self::createStub(Entry::class);
        $entry->method('getId')->willReturn(42);
        $entry->method('getTicket')->willReturn('ABC-1');
        $entry->method('getWorklogId')->willReturn(1001);
        $entry->method('getDay')->willReturn(new DateTime('2026-06-15'));
        $entry->method('getStart')->willReturn(new DateTime('1970-01-01 09:00:00'));
        $entry->method('getDuration')->willReturn(60);
        $entry->method('getDescription')->willReturn('fixed it');
        $entry->method('getActivity')->willReturn($activity);

        return $entry;
    }

    /**
     * A remote worklog that exactly matches linkedEntry()'s projection.
     */
    private function matchingRemote(): JiraWorkLog
    {
        return new JiraWorkLog(
            id: 1001,
            comment: '#42: Development: fixed it',
            started: new DateTime('2026-06-15 09:00:00')->format('Y-m-d\TH:i:s.000O'),
            timeSpentSeconds: 3600,
            updated: '2026-06-15T10:00:00.000+0200',
            authorAccountId: 'me',
        );
    }

    /**
     * @param list<string>                     $issueKeys
     * @param array<string, list<JiraWorkLog>> $worklogsByIssue
     */
    private function stubJira(array $issueKeys, array $worklogsByIssue): void
    {
        $this->api->method('getMyself')->willReturn(new JiraUserIdentity(accountId: 'me'));
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult($issueKeys, false));
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            static fn (string $key): array => $worklogsByIssue[$key] ?? [],
        );
    }

    private function verify(): \App\Entity\SyncRun
    {
        return $this->service->verify($this->user, $this->ticketSystem, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'));
    }

    public function testMatchingPairWithoutBaseCountsInSync(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([$this->linkedEntry()]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->stubJira(['ABC-1'], ['ABC-1' => [$this->matchingRemote()]]);

        $syncRun = $this->verify();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['in_sync'] ?? 0);
        self::assertCount(0, $syncRun->getItems());
    }

    public function testUnmatchedRemoteWorklogBecomesRemoteOnlyItem(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $remote = new JiraWorkLog(id: 2002, comment: 'jira-side work', started: '2026-06-10T14:00:00.000+0200', timeSpentSeconds: 1800, authorAccountId: 'me');
        $this->stubJira(['ABC-9'], ['ABC-9' => [$remote]]);

        $syncRun = $this->verify();

        self::assertSame(1, $syncRun->getCounters()['remote_only'] ?? 0);
        $items = $syncRun->getItems()->toArray();
        self::assertCount(1, $items);
        self::assertSame(SyncItemKind::REMOTE_ONLY, $items[0]->getKind());
        self::assertSame('ABC-9', $items[0]->getIssueKey());
        self::assertSame(2002, $items[0]->getRemoteWorklogId());
    }

    public function testForeignAuthorWorklogsAreIgnored(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $foreign = new JiraWorkLog(id: 3003, started: '2026-06-10T14:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'someone-else');
        $this->stubJira(['ABC-9'], ['ABC-9' => [$foreign]]);

        $syncRun = $this->verify();

        self::assertSame(0, $syncRun->getCounters()['remote_only'] ?? 0);
        self::assertCount(0, $syncRun->getItems());
    }

    public function testWorklogOutsideDateRangeIsIgnored(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $outside = new JiraWorkLog(id: 4004, started: '2026-05-31T14:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'me');
        $this->stubJira(['ABC-9'], ['ABC-9' => [$outside]]);

        $syncRun = $this->verify();

        self::assertCount(0, $syncRun->getItems());
    }

    public function testLinkedEntryWithMissingRemoteCountsLocalOnly(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([$this->linkedEntry()]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->stubJira([], []);

        $syncRun = $this->verify();

        self::assertSame(1, $syncRun->getCounters()['local_only'] ?? 0);
        self::assertSame(SyncItemKind::LOCAL_ONLY, $syncRun->getItems()->toArray()[0]->getKind());
    }

    public function testUnlinkedEntryCountsNeverSynced(): void
    {
        $entry = self::createStub(Entry::class);
        $entry->method('getId')->willReturn(43);
        $entry->method('getTicket')->willReturn('ABC-2');
        $entry->method('getWorklogId')->willReturn(null);
        $entry->method('getDay')->willReturn(new DateTime('2026-06-16'));
        $entry->method('getStart')->willReturn(new DateTime('1970-01-01 10:00:00'));
        $entry->method('getDuration')->willReturn(30);
        $entry->method('getDescription')->willReturn('x');
        $entry->method('getActivity')->willReturn(null);

        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([$entry]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->stubJira([], []);

        $syncRun = $this->verify();

        self::assertSame(1, $syncRun->getCounters()['never_synced'] ?? 0);
    }

    public function testDivergedPairProducesDivergedItemWithFieldPayload(): void
    {
        $entry = $this->linkedEntry();
        $remote = new JiraWorkLog(
            id: 1001,
            comment: '#42: Development: fixed it',
            started: new DateTime('2026-06-15 09:00:00')->format('Y-m-d\TH:i:s.000O'),
            timeSpentSeconds: 7200, // 120 min vs local 60 min
            authorAccountId: 'me',
        );
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([$entry]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->stubJira(['ABC-1'], ['ABC-1' => [$remote]]);

        $syncRun = $this->verify();

        self::assertSame(1, $syncRun->getCounters()['diverged'] ?? 0);
        $item = $syncRun->getItems()->toArray()[0];
        self::assertSame(SyncItemKind::DIVERGED, $item->getKind());
        self::assertSame(['duration'], $item->getPayload()['fields'] ?? null);
    }

    public function testTruncatedSearchIsReportedAsItem(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->api->method('getMyself')->willReturn(new JiraUserIdentity(accountId: 'me'));
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult(['ABC-1'], true));
        $this->api->method('getIssueWorklogs')->willReturn([]);

        $syncRun = $this->verify();

        $kinds = array_map(static fn ($item) => $item->getKind(), $syncRun->getItems()->toArray());
        self::assertContains(SyncItemKind::TRUNCATED, $kinds);
    }

    public function testUnparseableRemoteWorklogBecomesErrorItemAndRunContinues(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $broken = new JiraWorkLog(id: 5005, started: null, timeSpentSeconds: 600, authorAccountId: 'me');
        $this->stubJira(['ABC-9'], ['ABC-9' => [$broken]]);

        $syncRun = $this->verify();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['errors'] ?? 0);
        self::assertSame(SyncItemKind::ERROR, $syncRun->getItems()->toArray()[0]->getKind());
    }

    public function testIssueFetchFailureRecordsErrorAndContinuesWithOtherIssues(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->api->method('getMyself')->willReturn(new JiraUserIdentity(accountId: 'me'));
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult(['ABC-1', 'ABC-2'], false));
        $healthy = new JiraWorkLog(id: 6006, started: '2026-06-10T14:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'me');
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            static function (string $issueKey) use ($healthy): array {
                if ('ABC-1' === $issueKey) {
                    throw new RuntimeException('issue gone');
                }

                return [$healthy];
            },
        );

        $syncRun = $this->verify();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['errors'] ?? 0);
        self::assertSame(1, $syncRun->getCounters()['remote_only'] ?? 0);
        $kinds = array_map(static fn ($item) => $item->getKind(), $syncRun->getItems()->toArray());
        self::assertContains(SyncItemKind::ERROR, $kinds);
        self::assertContains(SyncItemKind::REMOTE_ONLY, $kinds);
    }
}
