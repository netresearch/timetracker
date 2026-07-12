<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Personio;

use App\DTO\Personio\AbsencePeriod;
use App\DTO\Personio\AbsenceType;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\PersonioAbsenceImport;
use App\Entity\PersonioConfig;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Repository\ActivityRepository;
use App\Repository\PersonioAbsenceImportRepository;
use App\Repository\PersonioConfigRepository;
use App\Repository\UserRepository;
use App\Service\Personio\AbsenceImportService;
use App\Service\Personio\PersonioClient;
use App\Service\Personio\PersonioClientFactory;
use App\Service\Tracking\DayClassService;
use App\Service\Util\ContractHoursResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;

use function array_filter;
use function array_values;
use function is_int;
use function str_contains;

#[CoversClass(AbsenceImportService::class)]
#[CoversClass(AbsencePeriod::class)]
#[CoversClass(AbsenceType::class)]
#[AllowMockObjectsWithoutExpectations]
final class AbsenceImportServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private PersonioAbsenceImportRepository&MockObject $importRepository;
    private PersonioConfigRepository&MockObject $configRepository;
    private PersonioClient&MockObject $client;
    private UserRepository&MockObject $userRepository;
    private ActivityRepository&MockObject $activityRepository;
    private AbsenceImportService $service;

    private Project $absenceProject;

    /** @var list<object> */
    private array $persisted = [];

    /** @var list<object> */
    private array $removed = [];

    /** @var array<int, Entry> Entry stub store for find(Entry::class, id) */
    private array $entriesById = [];

    /** @var array<string, PersonioAbsenceImport> keyed by absence id */
    private array $storedByAbsenceId = [];

    private int $entitySeq = 0;

    protected function setUp(): void
    {
        $this->absenceProject = new Project()->setName('Absence')->setCustomer(new Customer()->setName('Internal'));

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->method('persist')->willReturnCallback(function (object $object): void {
            $this->persisted[] = $object;
        });
        $this->entityManager->method('remove')->willReturnCallback(function (object $object): void {
            $this->removed[] = $object;
        });
        // Mimic the DB assigning ids on flush, so the import can record entry ids.
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            foreach ($this->persisted as $object) {
                if ($object instanceof Entry && null === $object->getId()) {
                    $this->setId($object, ++$this->entitySeq);
                }
            }
        });
        $this->entityManager->method('find')->willReturnCallback(
            fn (string $class, mixed $id): ?Entry => Entry::class === $class && is_int($id) ? ($this->entriesById[$id] ?? null) : null,
        );

        $this->importRepository = $this->createMock(PersonioAbsenceImportRepository::class);
        $this->importRepository->method('findOneByAbsenceId')->willReturnCallback(
            fn (string $absenceId): ?PersonioAbsenceImport => $this->storedByAbsenceId[$absenceId] ?? null,
        );
        $this->importRepository->method('findByUserIndexedByAbsenceId')->willReturnCallback(
            fn (): array => $this->storedByAbsenceId,
        );

        $this->configRepository = $this->createMock(PersonioConfigRepository::class);
        $this->client = $this->createMock(PersonioClient::class);
        $clientFactory = $this->createMock(PersonioClientFactory::class);
        $clientFactory->method('create')->willReturn($this->client);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->activityRepository = $this->createMock(ActivityRepository::class);
        $this->activityRepository->method('findOneByName')->willReturnCallback(
            static fn (string $name): Activity => new Activity()->setName($name),
        );

        $this->service = new AbsenceImportService(
            $this->entityManager,
            $this->importRepository,
            $this->configRepository,
            $clientFactory,
            $this->userRepository,
            $this->activityRepository,
            new ContractHoursResolver(),
            $this->createMock(DayClassService::class),
            new MockClock('2026-07-01 12:00:00'),
        );
    }

    public function testImportsNewVacationCreatesOneEntryPerWorkingDay(): void
    {
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([$this->type('type-vac', 'Urlaub')]);
        // Mon 06 -> Wed 08, all weekdays -> 3 entries at the default 8h (no contract).
        $this->client->method('listAbsencePeriods')->willReturn([
            $this->period('abs-1', '2026-07-06', '2026-07-08', 'type-vac'),
        ]);

        $run = $this->service->importUser($this->mappedUser(), $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(1, $run->getCounters()['imported'] ?? 0);

        $entries = $this->persistedEntries();
        self::assertCount(3, $entries);
        foreach ($entries as $entry) {
            self::assertSame(480, $entry->getDuration());
            self::assertSame('08:00:00', $entry->getStart()->format('H:i:s'));
            self::assertSame(EntrySource::HUMAN, $entry->getSource());
            self::assertSame('Urlaub', $entry->getActivity()?->getName());
            self::assertSame($this->absenceProject, $entry->getProject());
        }

        $state = $this->persistedState();
        self::assertCount(3, $state->getEntryIds());
        self::assertSame('type-vac', $state->getSignature()['typeId']);
    }

    public function testHalfDayBoundaryHalvesDuration(): void
    {
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([$this->type('type-vac', 'Urlaub')]);
        // Single day, marked a half day at both boundaries (start==end).
        $this->client->method('listAbsencePeriods')->willReturn([
            $this->period('abs-1', '2026-07-06', '2026-07-06', 'type-vac', 'SECOND_HALF', 'SECOND_HALF'),
        ]);

        $this->service->importUser($this->mappedUser(), $this->day('2026-07-01'), $this->day('2026-07-31'));

        $entries = $this->persistedEntries();
        self::assertCount(1, $entries);
        self::assertSame(240, $entries[0]->getDuration());
    }

    public function testWeekendDaysProduceNoEntry(): void
    {
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([$this->type('type-vac', 'Urlaub')]);
        // Fri 10 -> Mon 13 spans Sat+Sun: only Fri and Mon are working days.
        $this->client->method('listAbsencePeriods')->willReturn([
            $this->period('abs-1', '2026-07-10', '2026-07-13', 'type-vac'),
        ]);

        $this->service->importUser($this->mappedUser(), $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertCount(2, $this->persistedEntries());
    }

    public function testOpenEndedAbsenceCapsAtWindowEndAndRecordsIt(): void
    {
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([$this->type('type-sick', 'Krank')]);
        // No end date (open-ended sick leave): materialise up to the window end.
        $this->client->method('listAbsencePeriods')->willReturn([
            new AbsencePeriod('abs-1', '42', 'type-sick', '2026-07-06T00:00:00.000', null, null, null, 'APPROVED', null),
        ]);

        // Window Mon 06 -> Wed 08: three working days, capped at the window end.
        $this->service->importUser($this->mappedUser(), $this->day('2026-07-06'), $this->day('2026-07-08'));

        self::assertCount(3, $this->persistedEntries());
        // The effective (capped) end is recorded, so a later, wider window reads
        // as changed and extends the absence rather than skipping it.
        self::assertSame('2026-07-08', $this->persistedState()->getSignature()['end']);
    }

    public function testUnchangedAbsenceSkips(): void
    {
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([$this->type('type-vac', 'Urlaub')]);
        $period = $this->period('abs-1', '2026-07-06', '2026-07-06', 'type-vac');
        $this->client->method('listAbsencePeriods')->willReturn([$period]);

        $this->storedByAbsenceId['abs-1'] = new PersonioAbsenceImport()
            ->setUser($this->mappedUser())
            ->setAbsenceId('abs-1')
            ->setEntryIds([501])
            ->setSignature([
                'start' => '2026-07-06',
                'end' => '2026-07-06',
                'startHalf' => null,
                'endHalf' => null,
                'typeId' => 'type-vac',
            ])
            ->setLastImportedAt(new DateTimeImmutable('2026-06-30 09:00:00'));

        $run = $this->service->importUser($this->mappedUser(), $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertSame(1, $run->getCounters()['in_sync'] ?? 0);
        self::assertSame([], $this->persistedEntries());
    }

    public function testUnknownAbsenceTypeIsParked(): void
    {
        $this->activeConfig();
        // "Sabbatical" matches neither "krank" nor "urlaub" -> no TT activity.
        $this->client->method('listAbsenceTypes')->willReturn([$this->type('type-x', 'Sabbatical')]);
        $this->client->method('listAbsencePeriods')->willReturn([
            $this->period('abs-1', '2026-07-06', '2026-07-06', 'type-x'),
        ]);

        $run = $this->service->importUser($this->mappedUser(), $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertSame([], $this->persistedEntries());
        self::assertTrue($this->runHasItem($run, SyncItemKind::UNRESOLVED_ABSENCE_TYPE));
    }

    public function testHourlyTypeIsParked(): void
    {
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([$this->type('type-doc', 'Urlaub', 'HOUR')]);
        $this->client->method('listAbsencePeriods')->willReturn([
            $this->period('abs-1', '2026-07-06', '2026-07-06', 'type-doc'),
        ]);

        $run = $this->service->importUser($this->mappedUser(), $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertSame([], $this->persistedEntries());
        self::assertTrue($this->runHasItem($run, SyncItemKind::UNRESOLVED_ABSENCE_TYPE));
    }

    public function testCancelledAbsenceRemovesItsEntries(): void
    {
        $user = $this->mappedUser();
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([]);
        // Personio returns nothing in the window: the stored absence was cancelled.
        $this->client->method('listAbsencePeriods')->willReturn([]);

        $ownedEntry = $this->ownedEntry($user);
        $this->entriesById[501] = $ownedEntry;
        $this->storedByAbsenceId['abs-1'] = new PersonioAbsenceImport()
            ->setUser($user)
            ->setAbsenceId('abs-1')
            ->setEntryIds([501])
            ->setSignature(['start' => '2026-07-06', 'end' => '2026-07-06', 'startHalf' => null, 'endHalf' => null, 'typeId' => 'type-vac'])
            ->setLastImportedAt(new DateTimeImmutable('2026-06-30 09:00:00'));

        $run = $this->service->importUser($user, $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertSame(1, $run->getCounters()['cancelled'] ?? 0);
        self::assertContains($ownedEntry, $this->removed);
        self::assertContains($this->storedByAbsenceId['abs-1'], $this->removed);
    }

    public function testCancelledButLocallyModifiedEntryParksConflict(): void
    {
        $user = $this->mappedUser();
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([]);
        $this->client->method('listAbsencePeriods')->willReturn([]);

        // The entry was reassigned to a non-leave activity: it diverged, so the
        // cancellation must not delete it.
        $divergedEntry = new Entry()->setUser($user)->setProject($this->absenceProject)->setActivity(new Activity()->setName('Development'));
        $this->entriesById[501] = $divergedEntry;
        $this->storedByAbsenceId['abs-1'] = new PersonioAbsenceImport()
            ->setUser($user)
            ->setAbsenceId('abs-1')
            ->setEntryIds([501])
            ->setSignature(['start' => '2026-07-06', 'end' => '2026-07-06', 'startHalf' => null, 'endHalf' => null, 'typeId' => 'type-vac'])
            ->setLastImportedAt(new DateTimeImmutable('2026-06-30 09:00:00'));

        $run = $this->service->importUser($user, $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertSame(1, $run->getCounters()['conflicts'] ?? 0);
        self::assertTrue($this->runHasItem($run, SyncItemKind::CONFLICT));
        self::assertNotContains($divergedEntry, $this->removed);
    }

    public function testNoActiveConfigFailsRun(): void
    {
        $this->configRepository->method('findActive')->willReturn(null);

        $run = $this->service->importUser($this->mappedUser(), $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertSame(SyncRunStatus::FAILED, $run->getStatus());
        self::assertTrue($this->runHasItem($run, SyncItemKind::ERROR, 'no active Personio configuration'));
    }

    public function testNoAbsenceProjectFailsRun(): void
    {
        $this->configRepository->method('findActive')->willReturn(new PersonioConfig());

        $run = $this->service->importUser($this->mappedUser(), $this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertSame(SyncRunStatus::FAILED, $run->getStatus());
        self::assertTrue($this->runHasItem($run, SyncItemKind::ERROR, 'no absence project configured'));
    }

    public function testImportAllOptedInIteratesEnabledUsers(): void
    {
        $this->activeConfig();
        $this->client->method('listAbsenceTypes')->willReturn([]);
        $this->client->method('listAbsencePeriods')->willReturn([]);
        $this->userRepository->method('findPersonioExportEnabled')->willReturn([
            $this->mappedUser(),
            $this->mappedUser(),
        ]);

        $runs = $this->service->importAllOptedIn($this->day('2026-07-01'), $this->day('2026-07-31'));

        self::assertCount(2, $runs);
    }

    private function activeConfig(): void
    {
        $this->configRepository->method('findActive')->willReturn(
            new PersonioConfig()->setAbsenceProject($this->absenceProject),
        );
    }

    private function mappedUser(): User
    {
        return new User()->setPersonioEmployeeId(42);
    }

    private function ownedEntry(User $user): Entry
    {
        return new Entry()
            ->setUser($user)
            ->setProject($this->absenceProject)
            ->setActivity(new Activity()->setName(Activity::HOLIDAY))
            ->setDay('2026-07-06');
    }

    private function type(string $id, string $name, string $unit = 'DAY'): AbsenceType
    {
        return new AbsenceType($id, $name, null, $unit);
    }

    private function period(string $id, string $startDay, string $endDay, string $typeId, ?string $startHalf = null, ?string $endHalf = null): AbsencePeriod
    {
        return new AbsencePeriod(
            $id,
            '42',
            $typeId,
            $startDay . 'T00:00:00.000',
            $startHalf,
            $endDay . 'T00:00:00.000',
            $endHalf,
            'APPROVED',
            null,
        );
    }

    private function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00');
    }

    /**
     * @return list<Entry>
     */
    private function persistedEntries(): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $object): bool => $object instanceof Entry,
        ));
    }

    private function persistedState(): PersonioAbsenceImport
    {
        foreach ($this->persisted as $object) {
            if ($object instanceof PersonioAbsenceImport) {
                return $object;
            }
        }

        self::fail('No PersonioAbsenceImport was persisted.');
    }

    private function setId(Entry $entry, int $id): void
    {
        $property = new ReflectionProperty(Entry::class, 'id');
        $property->setValue($entry, $id);
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
