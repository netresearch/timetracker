<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Personio;

use App\DTO\Personio\AbsencePeriod;
use App\DTO\Personio\AbsenceType;
use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\PersonioAbsenceImport;
use App\Entity\PersonioConfig;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Enum\EntryClass;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Repository\ActivityRepository;
use App\Repository\PersonioAbsenceImportRepository;
use App\Repository\PersonioConfigRepository;
use App\Repository\UserRepository;
use App\Service\Sync\AbstractSyncRunService;
use App\Service\Tracking\DayClassService;
use App\Service\Util\ContractHoursResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use RuntimeException;

use function mb_substr;
use function round;
use function str_contains;
use function usort;

/**
 * Imports opted-in users' Personio absences as TT day entries (ADR-024 §4).
 *
 * Per absence period, one entry is created per working day: start 08:00, duration
 * = the user's contract hours for that weekday ({@see ContractHoursResolver}),
 * halved when Personio marks the boundary a half day; activity resolved from the
 * Personio time-off type NAME ("krank" -> Krank, "urlaub" -> Urlaub), project =
 * the configured absence project. Unknown/hourly types are parked, never guessed.
 *
 * Idempotency & cancellation are tracked per Personio absence id in
 * {@see PersonioAbsenceImport}: a signature of the source absence detects change
 * (unchanged -> skip; changed -> rebuild), and a stored absence no longer present
 * in Personio's window is cancelled and its entries removed — both only while the
 * TT entries are still the untouched imports, else the case is parked. No
 * EntryEvent is dispatched (no Jira echo); day classes are recalculated as in the
 * Jira import.
 */
class AbsenceImportService extends AbstractSyncRunService
{
    /** Every absence entry starts at 08:00 (ADR-024 §4). */
    private const string START_TIME = '08:00:00';

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly PersonioAbsenceImportRepository $importRepository,
        private readonly PersonioConfigRepository $configRepository,
        private readonly PersonioClientFactory $clientFactory,
        private readonly UserRepository $userRepository,
        private readonly ActivityRepository $activityRepository,
        private readonly ContractHoursResolver $contractHoursResolver,
        private readonly DayClassService $dayClassService,
        ClockInterface $clock,
    ) {
        parent::__construct($entityManager, $clock);
    }

    /**
     * Imports one user's absences across the window, producing a single run.
     */
    public function importUser(User $user, DateTimeImmutable $from, DateTimeImmutable $to): SyncRun
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::PERSONIO_IMPORT)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setTriggeredBy($user)
            ->setScope([
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ])
            ->setCounters([])
            ->setStartedAt($this->now());

        return $this->executeRun($syncRun, function () use ($syncRun, $user, $from, $to): void {
            $config = $this->configRepository->findActive();
            if (!$config instanceof PersonioConfig) {
                throw new RuntimeException('no active Personio configuration');
            }

            $absenceProject = $config->getAbsenceProject();
            if (!$absenceProject instanceof Project) {
                throw new RuntimeException('no absence project configured');
            }

            $employeeId = $user->getPersonioEmployeeId();
            if (null === $employeeId) {
                throw new RuntimeException('no Personio employee id mapped');
            }

            $client = $this->clientFactory->create($config);
            $personId = (string) $employeeId;

            $typesById = $this->indexTypes($client->listAbsenceTypes());
            $contracts = $this->sortedContracts($user);

            /** @var array<string, true> $seen absence ids present in this window */
            $seen = [];
            /** @var array<string, true> $affectedDays 'Y-m-d' keys to recalculate */
            $affectedDays = [];

            foreach ($client->listAbsencePeriods($personId, $from, $to) as $period) {
                if (null === $period->id) {
                    continue;
                }
                $seen[$period->id] = true;
                $this->importAbsence($syncRun, $user, $period, $typesById, $absenceProject, $contracts, $to, $affectedDays);
            }

            $this->handleCancellations($syncRun, $user, $from, $to, $seen, $absenceProject, $affectedDays);

            foreach (array_keys($affectedDays) as $dayKey) {
                $this->dayClassService->recalculate((int) $user->getId(), $dayKey);
            }
        });
    }

    /**
     * Cron entry point: import for every opted-in, employee-mapped user.
     *
     * @return list<SyncRun>
     */
    public function importAllOptedIn(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $runs = [];
        foreach ($this->userRepository->findPersonioExportEnabled() as $user) {
            $runs[] = $this->importUser($user, $from, $to);
        }

        return $runs;
    }

    /**
     * @param array<string, AbsenceType> $typesById
     * @param Contract[]                 $contracts
     * @param array<string, true>        $affectedDays
     */
    private function importAbsence(SyncRun $syncRun, User $user, AbsencePeriod $period, array $typesById, Project $absenceProject, array $contracts, DateTimeImmutable $windowTo, array &$affectedDays): void
    {
        $type = $typesById[$period->absenceTypeId] ?? null;
        if (!$type instanceof AbsenceType || !$type->isDayBased()) {
            $this->parkUnresolvedType($syncRun, $period, $type);

            return;
        }

        $activity = $this->resolveActivity($type);
        if (!$activity instanceof Activity) {
            $this->parkUnresolvedType($syncRun, $period, $type);

            return;
        }

        $start = new DateTimeImmutable($period->startDateTime)->setTime(0, 0);
        // A bounded absence materialises its FULL span (once); only an open-ended
        // one (no end — e.g. long-term sick leave) is capped at the window end.
        // The effective end goes into the signature, so as the window rolls the
        // open-ended case reads as changed and extends, while a bounded one stays
        // in sync — never leaving a long absence half-imported.
        $last = null !== $period->endDateTime
            ? new DateTimeImmutable($period->endDateTime)->setTime(0, 0)
            : $windowTo->setTime(0, 0);

        $signature = $this->signatureOf($period, $start, $last);
        $stored = $this->importRepository->findOneByAbsenceId((string) $period->id);

        if ($stored instanceof PersonioAbsenceImport && $stored->getSignature() === $signature) {
            $syncRun->incrementCounter('in_sync');

            return;
        }

        // Changed absence: only rebuild when the existing entries are still the
        // untouched imports; a locally edited entry parks the case instead.
        if ($stored instanceof PersonioAbsenceImport && !$this->removeOwnedEntries($stored, $absenceProject, $affectedDays)) {
            $syncRun->incrementCounter('conflicts');
            $this->addItem($syncRun, SyncItemKind::CONFLICT, issueKey: (string) $period->id, reason: 'absence entries modified locally; not rebuilt');

            return;
        }

        $entryIds = $this->createEntries($user, $period, $activity, $absenceProject, $contracts, $start, $last, $affectedDays, $syncRun);
        $this->persistState($syncRun, $user, (string) $period->id, $entryIds, $signature, $stored);
        $syncRun->incrementCounter($stored instanceof PersonioAbsenceImport ? 'updated' : 'imported');
    }

    /**
     * Creates one entry per working day of the absence and returns the new ids.
     *
     * @param Contract[]          $contracts
     * @param array<string, true> $affectedDays
     *
     * @return list<int>
     */
    private function createEntries(User $user, AbsencePeriod $period, Activity $activity, Project $absenceProject, array $contracts, DateTimeImmutable $start, DateTimeImmutable $last, array &$affectedDays, SyncRun $syncRun): array
    {
        $customer = $absenceProject->getCustomer();
        $description = mb_substr($this->describe($period, $activity), 0, Entry::DESCRIPTION_MAX_LENGTH);

        $entries = [];
        $current = $start;
        while ($current <= $last) {
            $minutes = $this->minutesFor($current, $start, $last, $period, $contracts, $syncRun);
            if ($minutes > 0) {
                $entry = $this->buildEntry($user, $current, $minutes, $activity, $absenceProject, $customer, $description);
                $this->entityManager->persist($entry);
                $entries[] = $entry;
                $affectedDays[$current->format('Y-m-d')] = true;
            }
            $current = $current->modify('+1 day');
        }

        // One flush so the created entries get their ids before they are recorded.
        if ([] !== $entries) {
            $this->entityManager->flush();
        }

        $entryIds = [];
        foreach ($entries as $entry) {
            $id = $entry->getId();
            if (null !== $id) {
                $entryIds[] = $id;
            }
        }

        return $entryIds;
    }

    /**
     * The entry duration for one absence day: the weekday's contract hours,
     * halved when this day is a half-day boundary. Zero on a non-working day.
     *
     * @param Contract[] $contracts
     */
    private function minutesFor(DateTimeImmutable $day, DateTimeImmutable $start, DateTimeImmutable $last, AbsencePeriod $period, array $contracts, SyncRun $syncRun): int
    {
        $contract = $this->contractHoursResolver->validContract($contracts, $day);
        if (!$contract instanceof Contract) {
            $syncRun->incrementCounter('no_contract');
        }

        $hours = $this->contractHoursResolver->weekdayHours($contract, (int) $day->format('w'));
        if ($hours <= 0.0) {
            return 0;
        }

        $dayKey = $day->format('Y-m-d');
        $halfStart = $dayKey === $start->format('Y-m-d') && $period->startsHalf();
        $halfEnd = $dayKey === $last->format('Y-m-d') && $period->endsHalf();
        if ($halfStart || $halfEnd) {
            $hours /= 2;
        }

        return (int) round($hours * 60);
    }

    private function buildEntry(User $user, DateTimeImmutable $day, int $minutes, Activity $activity, Project $absenceProject, ?Customer $customer, string $description): Entry
    {
        $entry = new Entry()
            ->setUser($user)
            ->setProject($absenceProject)
            ->setActivity($activity)
            ->setDescription($description)
            ->setDay($day->format('Y-m-d'))
            ->setStart(self::START_TIME)
            ->setEnd(new DateTimeImmutable(self::START_TIME)->modify('+' . $minutes . ' minutes')->format('H:i:s'))
            ->setDuration($minutes)
            ->setClass(EntryClass::PLAIN);

        if ($customer instanceof Customer) {
            $entry->setCustomer($customer);
        }

        return $entry;
    }

    /**
     * Stored absences no longer present in Personio's window are cancellations:
     * remove their entries when still untouched, else park the case.
     *
     * @param array<string, true> $seen
     * @param array<string, true> $affectedDays
     */
    private function handleCancellations(SyncRun $syncRun, User $user, DateTimeImmutable $from, DateTimeImmutable $to, array $seen, Project $absenceProject, array &$affectedDays): void
    {
        $fromKey = $from->format('Y-m-d');
        $toKey = $to->format('Y-m-d');

        foreach ($this->importRepository->findByUserIndexedByAbsenceId($user) as $absenceId => $stored) {
            if (isset($seen[$absenceId])) {
                continue;
            }

            // Only records whose absence started inside this window are in scope;
            // one outside the window is simply not fetched, not cancelled.
            $signatureStart = (string) ($stored->getSignature()['start'] ?? '');
            if ('' === $signatureStart) {
                continue;
            }
            if ($signatureStart < $fromKey) {
                continue;
            }
            if ($signatureStart > $toKey) {
                continue;
            }

            if (!$this->removeOwnedEntries($stored, $absenceProject, $affectedDays)) {
                $syncRun->incrementCounter('conflicts');
                $this->addItem($syncRun, SyncItemKind::CONFLICT, issueKey: $absenceId, reason: 'cancelled absence has locally-modified entries; not removed');

                continue;
            }

            $this->entityManager->remove($stored);
            $syncRun->incrementCounter('cancelled');
        }
    }

    /**
     * Deletes the entries a stored absence created, but ONLY while each is still
     * the untouched import (right project + a leave activity). Returns false —
     * touching nothing — if any entry diverged, so the caller can park it.
     *
     * @param array<string, true> $affectedDays
     */
    private function removeOwnedEntries(PersonioAbsenceImport $stored, Project $absenceProject, array &$affectedDays): bool
    {
        $entries = [];
        foreach ($stored->getEntryIds() as $entryId) {
            $entry = $this->entityManager->find(Entry::class, $entryId);
            if (null === $entry) {
                continue; // already gone — nothing to guard or delete
            }
            if (!$this->isOwnedImportEntry($entry, $absenceProject)) {
                return false; // diverged: refuse to touch any of them
            }
            $entries[] = $entry;
        }

        foreach ($entries as $entry) {
            $day = $entry->getDay();
            if (null !== $day) {
                $affectedDays[$day->format('Y-m-d')] = true;
            }
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();

        return true;
    }

    private function isOwnedImportEntry(Entry $entry, Project $absenceProject): bool
    {
        $project = $entry->getProject();
        $activity = $entry->getActivity();

        return $project instanceof Project
            && $project->getId() === $absenceProject->getId()
            && $activity instanceof Activity
            && ($activity->isSick() || $activity->isHoliday());
    }

    /**
     * @param list<int>                  $entryIds
     * @param array<string, string|null> $signature
     */
    private function persistState(SyncRun $syncRun, User $user, string $absenceId, array $entryIds, array $signature, ?PersonioAbsenceImport $stored): void
    {
        if (!$stored instanceof PersonioAbsenceImport) {
            $stored = new PersonioAbsenceImport()->setUser($user)->setAbsenceId($absenceId);
            $this->entityManager->persist($stored);
        }

        $stored->setEntryIds($entryIds)
            ->setSignature($signature)
            ->setLastImportedAt($this->now())
            ->setLastSyncRun($syncRun);
    }

    private function resolveActivity(AbsenceType $type): ?Activity
    {
        $name = $type->normalizedName();
        if (str_contains($name, 'krank')) {
            return $this->activityRepository->findOneByName(Activity::SICK);
        }
        if (str_contains($name, 'urlaub')) {
            return $this->activityRepository->findOneByName(Activity::HOLIDAY);
        }

        return null;
    }

    private function parkUnresolvedType(SyncRun $syncRun, AbsencePeriod $period, ?AbsenceType $type): void
    {
        $syncRun->incrementCounter('unresolved_type');
        $this->addItem(
            $syncRun,
            SyncItemKind::UNRESOLVED_ABSENCE_TYPE,
            issueKey: (string) $period->id,
            reason: 'no TT activity for Personio absence type: ' . ($type instanceof AbsenceType ? $type->name : $period->absenceTypeId),
        );
    }

    private function describe(AbsencePeriod $period, Activity $activity): string
    {
        return null !== $period->comment && '' !== $period->comment ? $period->comment : $activity->getName();
    }

    /**
     * The fields that, when changed, mean the entries must be rebuilt. `end` is
     * the EFFECTIVE last day materialised (the window end for an open-ended
     * absence), not the raw remote end — so a rolling window extends an
     * open-ended absence while leaving a bounded one in sync.
     *
     * @return array<string, string|null>
     */
    private function signatureOf(AbsencePeriod $period, DateTimeImmutable $start, DateTimeImmutable $last): array
    {
        return [
            'start' => $start->format('Y-m-d'),
            'end' => $last->format('Y-m-d'),
            'startHalf' => $period->startHalf,
            'endHalf' => $period->endHalf,
            'typeId' => $period->absenceTypeId,
        ];
    }

    /**
     * @param list<AbsenceType> $types
     *
     * @return array<string, AbsenceType>
     */
    private function indexTypes(array $types): array
    {
        $indexed = [];
        foreach ($types as $type) {
            $indexed[$type->id] = $type;
        }

        return $indexed;
    }

    /**
     * The user's contracts, newest start first — the order
     * {@see ContractHoursResolver::validContract()} expects.
     *
     * @return Contract[]
     */
    private function sortedContracts(User $user): array
    {
        $contracts = $user->getContracts()->toArray();
        usort(
            $contracts,
            static fn (Contract $a, Contract $b): int => $b->getStart()->format('Y-m-d') <=> $a->getStart()->format('Y-m-d'),
        );

        return $contracts;
    }
}
