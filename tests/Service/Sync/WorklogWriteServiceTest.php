<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\WorklogSyncState;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\RemoteWorklogNormalizer;
use App\Service\Sync\WorklogWriteService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(WorklogWriteService::class)]
#[AllowMockObjectsWithoutExpectations]
final class WorklogWriteServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private WorklogSyncStateRepository&MockObject $syncStateRepository;

    private JiraOAuthApiService&MockObject $api;

    private WorklogWriteService $service;

    private TicketSystem $ticketSystem;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->syncStateRepository = $this->createMock(WorklogSyncStateRepository::class);
        $this->api = $this->createMock(JiraOAuthApiService::class);
        $this->ticketSystem = self::createStub(TicketSystem::class);

        $this->persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(function (object $object): void {
            $this->persisted[] = $object;
        });

        $this->service = new WorklogWriteService(
            $this->entityManager,
            $this->syncStateRepository,
            new RemoteWorklogNormalizer(),
            new MockClock('2026-07-09 12:00:00'),
        );
    }

    private function entry(string $ticket, ?int $worklogId): Entry
    {
        $entry = new Entry();
        $entry->setTicket($ticket);
        $entry->setWorklogId($worklogId);

        return $entry;
    }

    private function remote(string $updated): JiraWorkLog
    {
        return new JiraWorkLog(
            id: 77,
            comment: '#42: Development: fixed it',
            started: '2026-06-15T09:00:00.000+0200',
            timeSpentSeconds: 3600,
            updated: $updated,
        );
    }

    public function testEmptyTicketSkips(): void
    {
        $this->api->expects(self::never())->method('getIssueWorklog');
        $this->api->expects(self::never())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->push($this->api, $this->entry('', null), $this->ticketSystem);

        self::assertSame(WriteOutcome::SKIPPED, $outcome);
    }

    public function testCreatePathWritesAndSeedsBase(): void
    {
        $entry = $this->entry('ABC-1', null);

        $this->api->expects(self::once())
            ->method('updateEntryJiraWorkLog')
            ->willReturnCallback(static function (Entry $entry): void {
                $entry->setWorklogId(77);
            });
        $this->api->expects(self::once())
            ->method('getIssueWorklog')
            ->with('ABC-1', 77)
            ->willReturn($this->remote('U1'));
        $this->syncStateRepository->method('findOneBy')->willReturn(null);

        $outcome = $this->service->push($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::WRITTEN, $outcome);
        self::assertCount(1, $this->persisted);
        $state = $this->persisted[0];
        self::assertInstanceOf(WorklogSyncState::class, $state);
        self::assertSame('U1', $state->getBaseUpdatedAt());
        self::assertSame(WorklogSyncStatus::IN_SYNC, $state->getStatus());
    }

    public function testLeaseLostParksConflict(): void
    {
        $entry = $this->entry('ABC-1', 77);
        $state = new WorklogSyncState()->setBaseUpdatedAt('U0');

        $this->syncStateRepository->method('findOneBy')->willReturn($state);
        $this->api->expects(self::once())
            ->method('getIssueWorklog')
            ->with('ABC-1', 77)
            ->willReturn($this->remote('U9'));
        $this->api->expects(self::never())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->push($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::LEASE_LOST, $outcome);
        self::assertSame(WorklogSyncStatus::CONFLICT, $state->getStatus());
        self::assertNotNull($state->getConflictRemotePayload());
    }

    public function testLeasePassesWritesAndRefreshesBase(): void
    {
        $entry = $this->entry('ABC-1', 77);
        $state = new WorklogSyncState()->setBaseUpdatedAt('U1');

        $this->syncStateRepository->method('findOneBy')->willReturn($state);
        $this->api->expects(self::exactly(2))
            ->method('getIssueWorklog')
            ->with('ABC-1', 77)
            ->willReturnOnConsecutiveCalls($this->remote('U1'), $this->remote('U2'));
        $this->api->expects(self::once())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->push($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::WRITTEN, $outcome);
        self::assertSame('U2', $state->getBaseUpdatedAt());
        self::assertSame(WorklogSyncStatus::IN_SYNC, $state->getStatus());
    }

    public function testRemoteMissingMarksOrphaned(): void
    {
        $entry = $this->entry('ABC-1', 77);
        $state = new WorklogSyncState()->setBaseUpdatedAt('U1');

        $this->syncStateRepository->method('findOneBy')->willReturn($state);
        $this->api->expects(self::once())
            ->method('getIssueWorklog')
            ->with('ABC-1', 77)
            ->willReturn(null);
        $this->api->expects(self::never())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->push($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::REMOTE_MISSING, $outcome);
        self::assertSame(WorklogSyncStatus::ORPHANED, $state->getStatus());
        self::assertSame(77, $entry->getWorklogId());
    }

    public function testForcePushSkipsLeaseAndWrites(): void
    {
        $entry = $this->entry('ABC-1', 77);
        $state = new WorklogSyncState()->setBaseUpdatedAt('U0');

        $this->syncStateRepository->method('findOneBy')->willReturn($state);
        // Exactly one remote GET — the post-write base refresh, never a lease compare.
        $this->api->expects(self::once())
            ->method('getIssueWorklog')
            ->with('ABC-1', 77)
            ->willReturn($this->remote('U9'));
        $this->api->expects(self::once())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->forcePush($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::WRITTEN, $outcome);
        self::assertSame('U9', $state->getBaseUpdatedAt());
        self::assertSame(WorklogSyncStatus::IN_SYNC, $state->getStatus());
        self::assertNull($state->getConflictRemotePayload());
    }

    public function testForcePushEmptyTicketSkips(): void
    {
        $this->api->expects(self::never())->method('getIssueWorklog');
        $this->api->expects(self::never())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->forcePush($this->api, $this->entry('', 77), $this->ticketSystem);

        self::assertSame(WriteOutcome::SKIPPED, $outcome);
    }

    public function testMissingStateBootstrapsBase(): void
    {
        $entry = $this->entry('ABC-1', 77);

        $this->syncStateRepository->method('findOneBy')->willReturn(null);
        $this->api->expects(self::exactly(2))
            ->method('getIssueWorklog')
            ->with('ABC-1', 77)
            ->willReturnOnConsecutiveCalls($this->remote('U5'), $this->remote('U6'));
        $this->api->expects(self::once())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->push($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::WRITTEN, $outcome);
        self::assertCount(1, $this->persisted);
        $state = $this->persisted[0];
        self::assertInstanceOf(WorklogSyncState::class, $state);
        self::assertSame('U6', $state->getBaseUpdatedAt());
        self::assertSame(WorklogSyncStatus::IN_SYNC, $state->getStatus());
    }
}
