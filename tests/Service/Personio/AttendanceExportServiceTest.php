<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Personio;

use App\Entity\Entry;
use App\Entity\PersonioAttendanceExport;
use App\Entity\PersonioConfig;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Exception\Personio\PersonioApiException;
use App\Repository\EntryRepository;
use App\Repository\PersonioAttendanceExportRepository;
use App\Repository\PersonioConfigRepository;
use App\Repository\UserRepository;
use App\Service\Personio\AttendanceExportService;
use App\Service\Personio\AttendanceProjector;
use App\Service\Personio\PersonioClient;
use App\Service\Personio\PersonioClientFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function array_filter;
use function array_map;
use function array_values;

#[CoversClass(AttendanceExportService::class)]
#[AllowMockObjectsWithoutExpectations]
final class AttendanceExportServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private EntryRepository&MockObject $entryRepository;
    private PersonioAttendanceExportRepository&MockObject $exportRepository;
    private PersonioConfigRepository&MockObject $configRepository;
    private PersonioClientFactory&MockObject $clientFactory;
    private PersonioClient&MockObject $client;
    private UserRepository&MockObject $userRepository;
    private AttendanceExportService $service;

    /** @var list<object> */
    private array $persisted = [];

    /** @var list<object> */
    private array $removed = [];

    /** @var array<string, list<Entry>> keyed by Y-m-d */
    private array $entriesByDay = [];

    /** @var array<string, PersonioAttendanceExport> keyed by Y-m-d */
    private array $stateByDay = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->method('persist')->willReturnCallback(
            function (object $object): void { $this->persisted[] = $object; },
        );
        $this->entityManager->method('remove')->willReturnCallback(
            function (object $object): void { $this->removed[] = $object; },
        );

        $this->entryRepository = $this->createMock(EntryRepository::class);
        $this->entryRepository->method('findByDay')->willReturnCallback(
            function (int $userId, string $day, ?EntrySource $source = null): array {
                $entries = $this->entriesByDay[$day] ?? [];
                if ($source instanceof EntrySource) {
                    $entries = array_values(array_filter(
                        $entries,
                        static fn (Entry $entry): bool => $entry->getSource() === $source,
                    ));
                }

                return $entries;
            },
        );

        $this->exportRepository = $this->createMock(PersonioAttendanceExportRepository::class);
        $this->exportRepository->method('findOneByUserAndDay')->willReturnCallback(
            fn (User $user, DateTimeImmutable $day): ?PersonioAttendanceExport => $this->stateByDay[$day->format('Y-m-d')] ?? null,
        );

        $this->configRepository = $this->createMock(PersonioConfigRepository::class);
        $this->client = $this->createMock(PersonioClient::class);
        $this->clientFactory = $this->createMock(PersonioClientFactory::class);
        $this->clientFactory->method('create')->willReturn($this->client);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->service = new AttendanceExportService(
            $this->entityManager,
            $this->entryRepository,
            $this->exportRepository,
            $this->configRepository,
            $this->clientFactory,
            $this->userRepository,
            new AttendanceProjector(),
            new MockClock('2026-07-01 12:00:00'),
        );
    }

    public function testExportsNewDayCreatesPeriodsAndState(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        $this->entriesByDay['2026-07-01'] = [
            $this->entry($user, '2026-07-01', '09:00:00', '10:00:00'),
            $this->entry($user, '2026-07-01', '11:00:00', '12:00:00'),
        ];

        $this->client->expects(self::exactly(2))
            ->method('createAttendancePeriod')
            ->willReturnOnConsecutiveCalls('1001', '1002');
        $this->client->expects(self::never())->method('updateAttendancePeriod');
        $this->client->expects(self::never())->method('deleteAttendancePeriod');

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(2, $run->getCounters()['created'] ?? 0);

        $state = $this->persistedExport();
        self::assertSame(['1001', '1002'], $state->getPeriodIds());
        self::assertCount(2, $state->getBasePayload());
    }

    public function testAgentIntervalsAreExcludedFromExport(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        // A human WORK interval and an agent wall-clock interval on the same day.
        $this->entriesByDay['2026-07-01'] = [
            $this->entry($user, '2026-07-01', '09:00:00', '10:00:00'),
            $this->entry($user, '2026-07-01', '11:00:00', '13:00:00')->setSource(EntrySource::AGENT),
        ];

        // Only the human interval becomes a Personio period (ArbZG boundary).
        $this->client->expects(self::once())
            ->method('createAttendancePeriod')
            ->willReturn('1001');
        $this->client->expects(self::never())->method('updateAttendancePeriod');
        $this->client->expects(self::never())->method('deleteAttendancePeriod');

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(1, $run->getCounters()['created'] ?? 0);
        self::assertSame(['1001'], $this->persistedExport()->getPeriodIds());
    }

    public function testUnchangedDaySkips(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        $entries = [$this->entry($user, '2026-07-01', '09:00:00', '10:00:00')];
        $this->entriesByDay['2026-07-01'] = $entries;

        $intervals = new AttendanceProjector()->project($entries);
        $state = new PersonioAttendanceExport()
            ->setUser($user)
            ->setDay($this->day('2026-07-01'))
            ->setPeriodIds(['1001'])
            ->setBasePayload(array_map(static fn ($i): array => $i->toArray(), $intervals))
            ->setLastExportedAt(new DateTimeImmutable('2026-06-30 10:00:00'));
        $this->stateByDay['2026-07-01'] = $state;

        $this->client->expects(self::never())->method('createAttendancePeriod');
        $this->client->expects(self::never())->method('updateAttendancePeriod');
        $this->client->expects(self::never())->method('deleteAttendancePeriod');

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(1, $run->getCounters()['in_sync'] ?? 0);
    }

    public function testChangedIntervalPatches(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        $this->entriesByDay['2026-07-01'] = [$this->entry($user, '2026-07-01', '09:00:00', '11:00:00')];

        $state = new PersonioAttendanceExport()
            ->setUser($user)
            ->setDay($this->day('2026-07-01'))
            ->setPeriodIds(['1001'])
            ->setBasePayload([['start' => 1, 'end' => 2]])
            ->setLastExportedAt(new DateTimeImmutable('2026-06-30 10:00:00'));
        $this->stateByDay['2026-07-01'] = $state;

        $this->client->expects(self::once())
            ->method('updateAttendancePeriod')
            ->with('1001', self::anything(), self::anything());
        $this->client->expects(self::never())->method('createAttendancePeriod');
        $this->client->expects(self::never())->method('deleteAttendancePeriod');

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(1, $run->getCounters()['updated'] ?? 0);
        self::assertSame(['1001'], $state->getPeriodIds());
    }

    public function testEmptiedDayDeletesPeriodsAndState(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        // No entries for the day.
        $state = new PersonioAttendanceExport()
            ->setUser($user)
            ->setDay($this->day('2026-07-01'))
            ->setPeriodIds(['1001'])
            ->setBasePayload([['start' => 1, 'end' => 2]])
            ->setLastExportedAt(new DateTimeImmutable('2026-06-30 10:00:00'));
        $this->stateByDay['2026-07-01'] = $state;

        $this->client->expects(self::once())->method('deleteAttendancePeriod')->with('1001');
        $this->client->expects(self::never())->method('createAttendancePeriod');
        $this->client->expects(self::never())->method('updateAttendancePeriod');

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(1, $run->getCounters()['deleted'] ?? 0);
        self::assertContains($state, $this->removed);
    }

    public function testApprovedRejectionParksConflict(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        $this->entriesByDay['2026-07-01'] = [$this->entry($user, '2026-07-01', '09:00:00', '11:00:00')];

        $state = new PersonioAttendanceExport()
            ->setUser($user)
            ->setDay($this->day('2026-07-01'))
            ->setPeriodIds(['1001'])
            ->setBasePayload([['start' => 1, 'end' => 2]])
            ->setLastExportedAt(new DateTimeImmutable('2026-06-30 10:00:00'));
        $this->stateByDay['2026-07-01'] = $state;

        $this->client->method('updateAttendancePeriod')
            ->willThrowException(new PersonioApiException('attendance approved', 403));

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(1, $run->getCounters()['conflicts'] ?? 0);
        self::assertTrue($this->runHasItem($run, SyncItemKind::CONFLICT));
        // The TT-owned id survives so a later run can retry once the approval is lifted.
        self::assertSame(['1001'], $state->getPeriodIds());
        // The base still reflects what Personio holds (the OLD value), not the rejected projection —
        // so the next run diffs again and retries instead of recording the change as in sync.
        self::assertSame([['start' => 1, 'end' => 2]], $state->getBasePayload());
    }

    public function testFailedUpdateKeepsOldBaseSoNextRunRetries(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        $this->entriesByDay['2026-07-01'] = [$this->entry($user, '2026-07-01', '09:00:00', '11:00:00')];

        $state = new PersonioAttendanceExport()
            ->setUser($user)
            ->setDay($this->day('2026-07-01'))
            ->setPeriodIds(['1001'])
            ->setBasePayload([['start' => 1, 'end' => 2]])
            ->setLastExportedAt(new DateTimeImmutable('2026-06-30 10:00:00'));
        $this->stateByDay['2026-07-01'] = $state;

        // A transient (non-approval) failure: the PATCH did not land.
        $this->client->method('updateAttendancePeriod')
            ->willThrowException(new PersonioApiException('gateway timeout', 500));

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(1, $run->getCounters()['errors'] ?? 0);
        self::assertTrue($this->runHasItem($run, SyncItemKind::ERROR));
        self::assertSame(['1001'], $state->getPeriodIds());
        // The write failed, so the base MUST stay the old value — recording the projected interval
        // here would falsely mark the day in sync and the failed change would never be retried.
        self::assertSame([['start' => 1, 'end' => 2]], $state->getBasePayload());
    }

    public function testNoEmployeeIdFailsRun(): void
    {
        $user = new User();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());

        $this->clientFactory->expects(self::never())->method('create');

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::FAILED, $run->getStatus());
        self::assertTrue($this->runHasItem($run, SyncItemKind::ERROR, 'no Personio employee id mapped'));
    }

    public function testNoActiveConfigFailsRun(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(null);

        $this->clientFactory->expects(self::never())->method('create');

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertSame(SyncRunStatus::FAILED, $run->getStatus());
        self::assertTrue($this->runHasItem($run, SyncItemKind::ERROR, 'no active Personio configuration'));
    }

    public function testDryRunPerformsNoWrites(): void
    {
        $user = $this->mappedUser();
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        $this->entriesByDay['2026-07-01'] = [
            $this->entry($user, '2026-07-01', '09:00:00', '10:00:00'),
            $this->entry($user, '2026-07-01', '11:00:00', '12:00:00'),
        ];

        $this->client->expects(self::never())->method('createAttendancePeriod');
        $this->client->expects(self::never())->method('updateAttendancePeriod');
        $this->client->expects(self::never())->method('deleteAttendancePeriod');

        $run = $this->service->exportUser($user, $this->day('2026-07-01'), $this->day('2026-07-01'), true);

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(2, $run->getCounters()['would_create'] ?? 0);
        self::assertNull($this->firstPersisted(PersonioAttendanceExport::class));
    }

    public function testExportAllOptedInIteratesEnabledUsers(): void
    {
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());
        $this->userRepository->method('findPersonioExportEnabled')->willReturn([
            $this->mappedUser(),
            $this->mappedUser(),
        ]);

        $runs = $this->service->exportAllOptedIn($this->day('2026-07-01'), $this->day('2026-07-01'));

        self::assertCount(2, $runs);
        foreach ($runs as $run) {
            self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        }
    }

    private function mappedUser(): User
    {
        return new User()->setPersonioEmployeeId(42);
    }

    private function entry(User $user, string $day, string $start, string $end): Entry
    {
        return new Entry()
            ->setUser($user)
            ->setDay($day)
            ->setStart($start)
            ->setEnd($end);
    }

    private function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00');
    }

    private function persistedExport(): PersonioAttendanceExport
    {
        $state = $this->firstPersisted(PersonioAttendanceExport::class);
        self::assertInstanceOf(PersonioAttendanceExport::class, $state);

        return $state;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function firstPersisted(string $class): ?object
    {
        foreach ($this->persisted as $object) {
            if ($object instanceof $class) {
                return $object;
            }
        }

        return null;
    }

    private function runHasItem(SyncRun $run, SyncItemKind $kind, ?string $reasonContains = null): bool
    {
        foreach ($run->getItems() as $item) {
            if ($item->getKind() !== $kind) {
                continue;
            }

            if (null === $reasonContains || str_contains($item->getReason(), $reasonContains)) {
                return true;
            }
        }

        return false;
    }
}
