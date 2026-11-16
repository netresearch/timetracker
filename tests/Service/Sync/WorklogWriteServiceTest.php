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
use App\Enum\EntrySource;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\RemoteWorklogNormalizer;
use App\Service\Sync\WorklogWriteService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAgentEntryIsNeverPushed(): void
    {
        // ADR-025 §7: every modern push path funnels through here, so agent walltime
        // is refused at the source rather than at each caller.
        $this->api->expects(self::never())->method('getIssueWorklog');
        $this->api->expects(self::never())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->push($this->api, $this->entry('ABC-1', 77)->setSource(EntrySource::AGENT), $this->ticketSystem);

        self::assertSame(WriteOutcome::SKIPPED, $outcome);
    }

    public function testAgentEntryIsNeverForcePushed(): void
    {
        // Conflict resolution with winner=local forces a write; it must not recreate
        // or overwrite a worklog from agent walltime either.
        $this->api->expects(self::never())->method('getIssueWorklog');
        $this->api->expects(self::never())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->forcePush($this->api, $this->entry('ABC-1', 77)->setSource(EntrySource::AGENT), $this->ticketSystem);

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

    /**
     * @return iterable<string, array{bool}>
     */
    public static function baseStates(): iterable
    {
        yield 'with a base whose lease still holds' => [true];
        yield 'without a base (pre-ADR entry)' => [false];
    }

    /**
     * #941: the worklog is missing on the entry's ticket because an earlier move stopped
     * half-way; it is found on its old issue, deleted there and booked on the ticket.
     */
    #[DataProvider('baseStates')]
    public function testAWorklogFoundOnAnotherIssueIsMovedToTheTicket(bool $withState): void
    {
        $entry = $this->entry('ABC-1', 77);
        $state = $withState ? new WorklogSyncState()->setBaseUpdatedAt('U1') : null;

        $this->syncStateRepository->method('findOneBy')->willReturn($state);
        $this->api->method('getIssueWorklog')->willReturnOnConsecutiveCalls(null, $this->remote('U2'));
        $this->api->expects(self::once())->method('findEntryWorklog')->with($entry)->willReturn($this->movedRemote('U1'));
        $this->api->expects(self::once())->method('doesTicketExist')->with('ABC-1')->willReturn(true);
        $this->api->expects(self::once())->method('deleteIssueWorklog')->with('151932', 77);
        $this->api->expects(self::once())
            ->method('updateEntryJiraWorkLog')
            ->willReturnCallback(static function (Entry $entry): void {
                // The old id must be gone, or the write would PUT to it on the new ticket.
                self::assertNull($entry->getWorklogId());
                $entry->setWorklogId(88);
            });

        $outcome = $this->service->push($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::WRITTEN, $outcome);
        self::assertSame(88, $entry->getWorklogId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unmovableWorklogs(): iterable
    {
        yield 'not listed by Jira' => ['absent'];
        yield 'lookup fails' => ['lookupFails'];
        yield 'found, but changed since the base (lease lost)' => ['leaseLost'];
        yield 'found, but the entry\'s ticket does not exist' => ['noTicket'];
    }

    /**
     * Every path that cannot finish the move parks the entry as before and keeps its id.
     */
    #[DataProvider('unmovableWorklogs')]
    public function testAWorklogThatCannotBeMovedStaysParkedWithItsId(string $case): void
    {
        $entry = $this->entry('ABC-1', 77);
        $state = new WorklogSyncState()->setBaseUpdatedAt('U1');

        $this->syncStateRepository->method('findOneBy')->willReturn($state);
        $this->api->method('getIssueWorklog')->willReturn(null);
        $lookup = $this->api->method('findEntryWorklog');
        match ($case) {
            'absent' => $lookup->willReturn(null),
            'lookupFails' => $lookup->willThrowException(new JiraApiException('boom', 500)),
            default => $lookup->willReturn($this->movedRemote('leaseLost' === $case ? 'U9' : 'U1')),
        };
        $this->api->method('doesTicketExist')->willReturn('noTicket' !== $case);
        $this->api->expects(self::never())->method('deleteIssueWorklog');
        $this->api->expects(self::never())->method('updateEntryJiraWorkLog');

        $outcome = $this->service->push($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::REMOTE_MISSING, $outcome);
        self::assertSame(WorklogSyncStatus::ORPHANED, $state->getStatus());
        self::assertSame(77, $entry->getWorklogId());
    }

    /**
     * A refused token is not a missing worklog: the 401 reaches the caller, which asks the
     * user to connect Jira (#911), and the id stays linked.
     */
    public function testARefusedTokenDuringTheLookupPropagates(): void
    {
        $entry = $this->entry('ABC-1', 77);

        $this->syncStateRepository->method('findOneBy')->willReturn(new WorklogSyncState()->setBaseUpdatedAt('U1'));
        $this->api->method('getIssueWorklog')->willReturn(null);
        $this->api->method('findEntryWorklog')->willThrowException(new JiraApiUnauthorizedException('401', 401, 'https://tt.example.com/jira/connect/7'));
        $this->api->expects(self::never())->method('deleteIssueWorklog');

        try {
            $this->service->push($this->api, $entry, $this->ticketSystem);
            self::fail('A refused token must propagate');
        } catch (JiraApiUnauthorizedException) {
            self::assertSame(77, $entry->getWorklogId());
        }
    }

    private function movedRemote(string $updated): JiraWorkLog
    {
        return new JiraWorkLog(id: 77, timeSpentSeconds: 3600, updated: $updated, issueId: '151932');
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
