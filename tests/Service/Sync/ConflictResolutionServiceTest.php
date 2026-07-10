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
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\ConflictResolutionService;
use App\Service\Sync\EntryPullApplier;
use App\Service\Sync\EntryWorklogProjector;
use App\Service\Sync\RemoteWorklogNormalizer;
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

#[CoversClass(ConflictResolutionService::class)]
#[AllowMockObjectsWithoutExpectations]
final class ConflictResolutionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private JiraOAuthApiService&MockObject $api;

    private WorklogWriteService&MockObject $worklogWriteService;

    private EntryPullApplier&MockObject $entryPullApplier;

    private DayClassService&MockObject $dayClassService;

    private ConflictResolutionService $service;

    private TicketSystem&MockObject $ticketSystem;

    private User $actor;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->api = $this->createMock(JiraOAuthApiService::class);
        $this->worklogWriteService = $this->createMock(WorklogWriteService::class);
        $this->entryPullApplier = $this->createMock(EntryPullApplier::class);
        $this->dayClassService = $this->createMock(DayClassService::class);

        $apiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $apiFactory->method('create')->willReturn($this->api);

        $this->ticketSystem = $this->createMock(TicketSystem::class);

        $this->actor = new User()->setId(1)->setUsername('admin');

        $this->service = new ConflictResolutionService(
            $this->entityManager,
            $apiFactory,
            $this->worklogWriteService,
            $this->entryPullApplier,
            new EntryWorklogProjector(),
            new RemoteWorklogNormalizer(),
            $this->dayClassService,
            new MockClock('2026-07-09 12:00:00'),
        );
    }

    private function parkedState(WorklogSyncStatus $status): WorklogSyncState
    {
        $owner = new User()->setId(7)->setUsername('owner');
        $entry = new Entry()
            ->setId(42)
            ->setUser($owner)
            ->setTicket('TIM-1')
            ->setWorklogId(77)
            ->setDay('2026-06-10')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDuration(60)
            ->setDescription('fixed it');

        return new WorklogSyncState()
            ->setEntry($entry)
            ->setTicketSystem($this->ticketSystem)
            ->setStatus($status)
            ->setBaseUpdatedAt('U0')
            ->setConflictRemotePayload([
                'comment' => 'stale stored comment',
                'started' => '2026-06-10T08:00:00.000+0200',
                'timeSpentSeconds' => 1800,
                'updated' => 'U-STALE',
            ]);
    }

    private function liveRemote(): JiraWorkLog
    {
        return new JiraWorkLog(
            id: 77,
            comment: '#42: live comment',
            started: '2026-06-10T09:00:00.000+0200',
            timeSpentSeconds: 7200,
            updated: 'U-LIVE',
        );
    }

    public function testRejectsUnparkedState(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::IN_SYNC);

        $result = $this->service->resolve($state, 'local', $this->actor);

        self::assertFalse($result->resolved);
        self::assertSame('', $result->action);
        self::assertSame('state is not parked', $result->reason);
    }

    public function testRejectsInvalidWinner(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::CONFLICT);

        $result = $this->service->resolve($state, 'both', $this->actor);

        self::assertFalse($result->resolved);
        self::assertSame('', $result->action);
        self::assertNotSame('', $result->reason);
    }

    public function testLocalWinsForcePushes(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::CONFLICT);
        $entry = $state->getEntry();

        $this->worklogWriteService->expects(self::once())
            ->method('forcePush')
            ->with($this->api, $entry, $this->ticketSystem)
            ->willReturnCallback(static function () use ($state): WriteOutcome {
                $state->setStatus(WorklogSyncStatus::IN_SYNC); // refreshBase side effect

                return WriteOutcome::WRITTEN;
            });
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->resolve($state, 'local', $this->actor);

        self::assertTrue($result->resolved);
        self::assertSame('pushed_local', $result->action);
    }

    public function testLocalWinsOnOrphanedReportsRecreated(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::ORPHANED);

        $this->worklogWriteService->expects(self::once())
            ->method('forcePush')
            ->willReturnCallback(static function () use ($state): WriteOutcome {
                $state->setStatus(WorklogSyncStatus::IN_SYNC); // refreshBase side effect

                return WriteOutcome::WRITTEN;
            });

        $result = $this->service->resolve($state, 'local', $this->actor);

        self::assertTrue($result->resolved);
        self::assertSame('recreated_local', $result->action);
    }

    public function testLocalWinsWithFailedBaseRefreshStaysParked(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::CONFLICT);

        // forcePush reports WRITTEN but does NOT touch the state — the
        // refreshBase no-op case (post-write 404 race / missing worklog id).
        $this->worklogWriteService->expects(self::once())
            ->method('forcePush')
            ->willReturn(WriteOutcome::WRITTEN);

        $result = $this->service->resolve($state, 'local', $this->actor);

        self::assertFalse($result->resolved);
        self::assertStringContainsString('remains parked', $result->reason);
        self::assertSame(WorklogSyncStatus::CONFLICT, $state->getStatus());
    }

    public function testRemoteWinsPullsLiveRemote(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::CONFLICT);
        $live = $this->liveRemote();
        $liveSnapshot = new RemoteWorklogNormalizer()->normalize($live, 'TIM-1');

        $this->api->expects(self::once())
            ->method('getIssueWorklog')
            ->with('TIM-1', 77)
            ->willReturn($live);

        $appliedSnapshot = null;
        $this->entryPullApplier->expects(self::once())
            ->method('apply')
            ->willReturnCallback(static function (Entry $entry, WorklogSnapshot $remote, array $fields, TicketSystem $ticketSystem) use (&$appliedSnapshot): PullResult {
                $appliedSnapshot = $remote;

                return new PullResult(true, '', ['2026-06-10']);
            });
        $this->entityManager->expects(self::once())->method('flush');
        $this->dayClassService->expects(self::once())
            ->method('recalculate')
            ->with(7, '2026-06-10');

        $result = $this->service->resolve($state, 'remote', $this->actor);

        self::assertTrue($result->resolved);
        self::assertSame('pulled_remote', $result->action);
        // The applier got the LIVE snapshot, not the stale stored payload.
        self::assertInstanceOf(WorklogSnapshot::class, $appliedSnapshot);
        self::assertTrue($liveSnapshot->equals($appliedSnapshot));
        self::assertSame(WorklogSyncStatus::IN_SYNC, $state->getStatus());
        self::assertSame($liveSnapshot->toArray(), $state->getBasePayload());
        self::assertSame('U-LIVE', $state->getBaseUpdatedAt());
        self::assertNull($state->getConflictRemotePayload());
    }

    public function testRemoteWinsWithLiveGoneDeletesEntry(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::CONFLICT);
        $entry = $state->getEntry();

        $this->api->expects(self::once())
            ->method('getIssueWorklog')
            ->with('TIM-1', 77)
            ->willReturn(null);
        $this->entryPullApplier->expects(self::never())->method('apply');
        $this->entityManager->expects(self::once())->method('remove')->with($entry);
        $this->entityManager->expects(self::once())->method('flush');
        $this->dayClassService->expects(self::once())
            ->method('recalculate')
            ->with(7, '2026-06-10');

        $result = $this->service->resolve($state, 'remote', $this->actor);

        self::assertTrue($result->resolved);
        self::assertSame('deleted_local', $result->action);
    }

    public function testRemoteWinsOnOrphanedDeletesEntry(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::ORPHANED);
        $entry = $state->getEntry();

        $this->api->expects(self::never())->method('getIssueWorklog');
        $this->entityManager->expects(self::once())->method('remove')->with($entry);
        $this->entityManager->expects(self::once())->method('flush');
        $this->dayClassService->expects(self::once())
            ->method('recalculate')
            ->with(7, '2026-06-10');

        $result = $this->service->resolve($state, 'remote', $this->actor);

        self::assertTrue($result->resolved);
        self::assertSame('deleted_local', $result->action);
    }

    public function testPullFailureSurfacesReason(): void
    {
        $state = $this->parkedState(WorklogSyncStatus::CONFLICT);

        $this->api->method('getIssueWorklog')->willReturn($this->liveRemote());
        $this->entryPullApplier->method('apply')
            ->willReturn(new PullResult(false, 'worklog crosses midnight'));
        $this->entityManager->expects(self::never())->method('flush');
        $this->dayClassService->expects(self::never())->method('recalculate');

        $result = $this->service->resolve($state, 'remote', $this->actor);

        self::assertFalse($result->resolved);
        self::assertSame('', $result->action);
        self::assertSame('worklog crosses midnight', $result->reason);
    }
}
