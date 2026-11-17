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
use Psr\Log\LoggerInterface;
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
        $this->api->method('canWriteWorklogs')->willReturn(true);
        $this->api->expects(self::once())->method('doesTicketExist')->with('ABC-1')->willReturn(true);
        $calls = [];
        $this->api->expects(self::once())->method('deleteIssueWorklog')->with('151932', 77)
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'delete';
            });
        // The cleared id is stored before the new write: if that write fails, the next
        // save or sync books the entry anew instead of parking the deleted id.
        $this->entityManager->method('flush')->willReturnCallback(static function () use (&$calls, $entry): void {
            $calls[] = 'flush id ' . var_export($entry->getWorklogId(), true);
        });
        $this->api->expects(self::once())
            ->method('updateEntryJiraWorkLog')
            ->willReturnCallback(static function (Entry $entry) use (&$calls): void {
                // The old id must be gone, or the write would PUT to it on the new ticket.
                self::assertNull($entry->getWorklogId());
                $calls[] = 'write';
                $entry->setWorklogId(88);
            });

        $outcome = $this->service->push($this->api, $entry, $this->ticketSystem);

        self::assertSame(WriteOutcome::WRITTEN, $outcome);
        self::assertSame(88, $entry->getWorklogId());
        // Pending changes are stored before the delete, while the old id still holds.
        self::assertSame(['flush id 77', 'delete', 'flush id NULL', 'write', 'flush id 88'], $calls);
    }

    /**
     * A write that creates a worklog stores its new id before the base read: when
     * that read fails, the save path never reaches its own flush, and without this
     * one the next save would create a second worklog.
     */
    #[DataProvider('writesThatCreateAWorklog')]
    public function testANewWorklogIdIsStoredBeforeTheBaseReadThatFails(string $path): void
    {
        $entry = $this->entry('ABC-1', 'create' === $path ? null : 77);
        $this->syncStateRepository->method('findOneBy')->willReturn(null);
        $reads = 0;
        $this->api->method('getIssueWorklog')->willReturnCallback(static function () use (&$reads, $path): ?JiraWorkLog {
            // The move path first finds the worklog missing on the ticket; every later read is the base read.
            if ('move' === $path && 0 === $reads++) {
                return null;
            }

            throw new JiraApiException('base read failed', 500);
        });
        $this->api->method('findEntryWorklog')->willReturn($this->movedRemote('U1'));
        $this->api->method('canWriteWorklogs')->willReturn(true);
        $this->api->method('doesTicketExist')->willReturn(true);
        $this->api->method('updateEntryJiraWorkLog')->willReturnCallback(static function (Entry $written): void {
            $written->setWorklogId(88);
        });
        $flushedIds = [];
        $this->entityManager->method('flush')->willReturnCallback(static function () use (&$flushedIds, $entry): void {
            $flushedIds[] = $entry->getWorklogId();
        });

        try {
            'force' === $path
                ? $this->service->forcePush($this->api, $entry, $this->ticketSystem)
                : $this->service->push($this->api, $entry, $this->ticketSystem);
            self::fail('the failing base read must reach the caller');
        } catch (JiraApiException) {
        }

        self::assertContains(88, $flushedIds);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writesThatCreateAWorklog(): iterable
    {
        yield 'first write of an entry' => ['create'];
        yield 'conflict resolved with local wins' => ['force'];
        yield 'worklog moved to the entry\'s ticket' => ['move'];
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
        yield 'found, but the user does not write worklogs to this Jira' => ['noWrites'];
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
        // The write gate comes before the lookup: no worklog/list request then.
        $lookup = $this->api->expects('noWrites' === $case ? self::never() : self::once())->method('findEntryWorklog');
        match ($case) {
            'absent' => $lookup->willReturn(null),
            'lookupFails' => $lookup->willThrowException(new JiraApiException('boom', 500)),
            default => $lookup->willReturn($this->movedRemote('leaseLost' === $case ? 'U9' : 'U1')),
        };
        $this->api->method('doesTicketExist')->willReturn('noTicket' !== $case);
        $this->api->method('canWriteWorklogs')->willReturn('noWrites' !== $case);
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
        $this->api->method('canWriteWorklogs')->willReturn(true);
        $this->api->method('findEntryWorklog')->willThrowException(new JiraApiUnauthorizedException('401', 401, 'https://tt.example.com/jira/connect/7'));
        $this->api->expects(self::never())->method('deleteIssueWorklog');

        try {
            $this->service->push($this->api, $entry, $this->ticketSystem);
            self::fail('A refused token must propagate');
        } catch (JiraApiUnauthorizedException) {
            self::assertSame(77, $entry->getWorklogId());
        }
    }

    public function testAFailedDeleteOnTheOldIssuePropagatesAndKeepsTheId(): void
    {
        $entry = $this->entry('ABC-1', 77);

        $this->syncStateRepository->method('findOneBy')->willReturn(new WorklogSyncState()->setBaseUpdatedAt('U1'));
        $this->api->method('getIssueWorklog')->willReturn(null);
        $this->api->method('findEntryWorklog')->willReturn($this->movedRemote('U1'));
        $this->api->method('doesTicketExist')->willReturn(true);
        $this->api->method('canWriteWorklogs')->willReturn(true);
        $this->api->method('deleteIssueWorklog')->willThrowException(new JiraApiException('boom', 500));
        $this->api->expects(self::never())->method('updateEntryJiraWorkLog');

        try {
            $this->service->push($this->api, $entry, $this->ticketSystem);
            self::fail('A failed delete must propagate');
        } catch (JiraApiException) {
            self::assertSame(77, $entry->getWorklogId());
        }
    }

    /**
     * A failed lookup is logged at error: production's fingers_crossed handler drops
     * anything below error unless an error follows in the same request.
     */
    public function testAFailedLookupIsLoggedAtError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $service = new WorklogWriteService($this->entityManager, $this->syncStateRepository, new RemoteWorklogNormalizer(), new MockClock('2026-07-09 12:00:00'), $logger);

        $this->syncStateRepository->method('findOneBy')->willReturn(new WorklogSyncState()->setBaseUpdatedAt('U1'));
        $this->api->method('getIssueWorklog')->willReturn(null);
        $this->api->method('canWriteWorklogs')->willReturn(true);
        $this->api->method('findEntryWorklog')->willThrowException(new JiraApiException('boom', 500));

        self::assertSame(WriteOutcome::REMOTE_MISSING, $service->push($this->api, $this->entry('ABC-1', 77), $this->ticketSystem));
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
