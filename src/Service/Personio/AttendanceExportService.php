<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Personio;

use App\Entity\PersonioAttendanceExport;
use App\Entity\PersonioConfig;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Exception\Personio\PersonioApiException;
use App\Repository\EntryRepository;
use App\Repository\PersonioAttendanceExportRepository;
use App\Repository\PersonioConfigRepository;
use App\Repository\UserRepository;
use App\Service\Sync\AbstractSyncRunService;
use App\ValueObject\Personio\WorkInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function count;
use function date_default_timezone_get;
use function max;
use function substr;

/**
 * Exports opted-in users' TT worklogs to Personio as daily WORK attendance periods (ADR-024 §3).
 *
 * A day's entries are projected into overlap-free work intervals ({@see AttendanceProjector});
 * each interval maps to one Personio WORK period. TimeTracker owns only the periods it created,
 * tracked per (user, day) in {@see PersonioAttendanceExport}: on every run the stored id set is
 * reconciled positionally against the current projection — create the new, patch the changed,
 * delete the vanished — so the export is idempotent over a rolling window. Approved periods that
 * Personio refuses to modify (HTTP 403/409) are parked as conflicts and left untouched. Read-only
 * on the TT side: it only reads entries and never dispatches an EntryEvent.
 */
class AttendanceExportService extends AbstractSyncRunService
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
        private readonly PersonioAttendanceExportRepository $exportRepository,
        private readonly PersonioConfigRepository $configRepository,
        private readonly PersonioClientFactory $clientFactory,
        private readonly UserRepository $userRepository,
        private readonly AttendanceProjector $attendanceProjector,
        ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct($entityManager, $clock);
    }

    /**
     * Exports one user's attendances across the day window, producing a single run.
     */
    public function exportUser(User $user, DateTimeImmutable $from, DateTimeImmutable $to, bool $dryRun = false): SyncRun
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::PERSONIO_EXPORT)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setTriggeredBy($user)
            ->setScope([
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'dry_run' => $dryRun,
            ])
            ->setCounters([])
            ->setStartedAt($this->now());

        return $this->executeRun($syncRun, function () use ($syncRun, $user, $from, $to, $dryRun): void {
            $config = $this->configRepository->findActive();
            if (!$config instanceof PersonioConfig) {
                throw new RuntimeException('no active Personio configuration');
            }

            $employeeId = $user->getPersonioEmployeeId();
            if (null === $employeeId) {
                throw new RuntimeException('no Personio employee id mapped');
            }

            $client = $this->clientFactory->create($config);
            $personId = (string) $employeeId;

            $current = $from->setTime(0, 0);
            $last = $to->setTime(0, 0);
            while ($current <= $last) {
                $this->exportDay($syncRun, $client, $user, $personId, $current, $dryRun);
                $current = $current->modify('+1 day');
            }
        });
    }

    /**
     * Cron entry point: export every opted-in, employee-mapped user and return each run.
     *
     * @return list<SyncRun>
     */
    public function exportAllOptedIn(DateTimeImmutable $from, DateTimeImmutable $to, bool $dryRun = false): array
    {
        $runs = [];
        foreach ($this->userRepository->findPersonioExportEnabled() as $user) {
            $runs[] = $this->exportUser($user, $from, $to, $dryRun);
        }

        return $runs;
    }

    /**
     * Reconciles one day: project the entries, diff against the TT-owned period set, write the delta.
     */
    private function exportDay(SyncRun $syncRun, PersonioClient $client, User $user, string $personId, DateTimeImmutable $day, bool $dryRun): void
    {
        $intervals = $this->attendanceProjector->project(
            // ArbZG boundary (ADR-025 §5): only human labour becomes a Personio WORK
            // period; agent wall-clock must never be exported as working time.
            $this->entryRepository->findByDay((int) $user->getId(), $day->format('Y-m-d'), EntrySource::HUMAN),
        );
        $state = $this->exportRepository->findOneByUserAndDay($user, $day);

        if ([] === $intervals && !$state instanceof PersonioAttendanceExport) {
            return;
        }

        $storedIds = $state instanceof PersonioAttendanceExport ? $state->getPeriodIds() : [];
        $storedPayload = $state instanceof PersonioAttendanceExport ? $state->getBasePayload() : [];

        [$resultIds, $resultBases] = $this->reconcile($syncRun, $client, $personId, $intervals, $storedIds, $storedPayload, $dryRun);

        if ($dryRun) {
            return;
        }

        $this->persistState($syncRun, $user, $day, $resultBases, $resultIds, $state);
    }

    /**
     * Positional zip of the stored id set with the projected intervals; returns the id set the day
     * now owns (created + kept, minus deleted) AND the base value each retained period actually holds
     * in Personio — the base is NOT the aspirational projection: a failed write keeps the OLD base so
     * the next run retries it instead of falsely treating it as in sync.
     *
     * @param list<WorkInterval>                $intervals
     * @param list<string>                      $storedIds
     * @param list<array{start: int, end: int}> $storedPayload
     *
     * @return array{0: list<string>, 1: list<array{start: int, end: int}>}
     */
    private function reconcile(SyncRun $syncRun, PersonioClient $client, string $personId, array $intervals, array $storedIds, array $storedPayload, bool $dryRun): array
    {
        $resultIds = [];
        $resultBases = [];
        $slots = max(count($intervals), count($storedIds));

        for ($i = 0; $i < $slots; ++$i) {
            $slot = $this->reconcileSlot(
                $syncRun,
                $client,
                $personId,
                $intervals[$i] ?? null,
                $storedIds[$i] ?? null,
                $storedPayload[$i] ?? null,
                $dryRun,
            );
            if (null !== $slot) {
                $resultIds[] = $slot['id'];
                $resultBases[] = $slot['base'];
            }
        }

        return [$resultIds, $resultBases];
    }

    /**
     * @param array{start: int, end: int}|null $base
     *
     * @return array{id: string, base: array{start: int, end: int}}|null the retained period id and
     *                                                                   the base it actually holds in Personio
     */
    private function reconcileSlot(SyncRun $syncRun, PersonioClient $client, string $personId, ?WorkInterval $interval, ?string $storedId, ?array $base, bool $dryRun): ?array
    {
        if ($interval instanceof WorkInterval && null === $storedId) {
            return $this->createPeriod($syncRun, $client, $personId, $interval, $dryRun);
        }

        if ($interval instanceof WorkInterval) {
            return $this->updatePeriod($syncRun, $client, $storedId ?? '', $interval, $base, $dryRun);
        }

        if (null !== $storedId) {
            return $this->deletePeriod($syncRun, $client, $storedId, $base, $dryRun);
        }

        return null;
    }

    /**
     * @return array{id: string, base: array{start: int, end: int}}|null
     */
    private function createPeriod(SyncRun $syncRun, PersonioClient $client, string $personId, WorkInterval $interval, bool $dryRun): ?array
    {
        if ($dryRun) {
            $syncRun->incrementCounter('would_create');

            return null;
        }

        try {
            $id = $client->createAttendancePeriod($personId, 'WORK', $this->iso($interval->startTimestamp), $this->iso($interval->endTimestamp));
            $syncRun->incrementCounter('created');

            // Written: the interval is now the base Personio holds for this period.
            return ['id' => $id, 'base' => $interval->toArray()];
        } catch (PersonioApiException $personioApiException) {
            $this->handleWriteError($syncRun, $personioApiException, null);

            return null;
        }
    }

    /**
     * @param array{start: int, end: int}|null $base
     *
     * @return array{id: string, base: array{start: int, end: int}}
     */
    private function updatePeriod(SyncRun $syncRun, PersonioClient $client, string $storedId, WorkInterval $interval, ?array $base, bool $dryRun): array
    {
        if (null !== $base && $interval->toArray() === $base) {
            $syncRun->incrementCounter('in_sync');

            return ['id' => $storedId, 'base' => $base];
        }

        if ($dryRun) {
            $syncRun->incrementCounter('would_update');

            return ['id' => $storedId, 'base' => $interval->toArray()];
        }

        try {
            $client->updateAttendancePeriod($storedId, $this->iso($interval->startTimestamp), $this->iso($interval->endTimestamp));
            $syncRun->incrementCounter('updated');

            // Patched: the interval is what Personio now holds.
            return ['id' => $storedId, 'base' => $interval->toArray()];
        } catch (PersonioApiException $personioApiException) {
            $this->handleWriteError($syncRun, $personioApiException, $storedId);

            // Not written: Personio still holds the OLD base, so keep it — the next run then
            // sees a diff again and retries instead of falsely recording the change as in sync.
            // Base unknown (corrupt/legacy state) → a never-matching sentinel forces the retry.
            return ['id' => $storedId, 'base' => $base ?? ['start' => 0, 'end' => 0]];
        }
    }

    /**
     * @param array{start: int, end: int}|null $base
     *
     * @return array{id: string, base: array{start: int, end: int}}|null
     */
    private function deletePeriod(SyncRun $syncRun, PersonioClient $client, string $storedId, ?array $base, bool $dryRun): ?array
    {
        if ($dryRun) {
            $syncRun->incrementCounter('would_delete');

            return null;
        }

        try {
            $client->deleteAttendancePeriod($storedId);
            $syncRun->incrementCounter('deleted');

            return null;
        } catch (PersonioApiException $personioApiException) {
            $this->handleWriteError($syncRun, $personioApiException, $storedId);

            // Not deleted: retain the id AND its base so a later run retries the removal.
            return ['id' => $storedId, 'base' => $base ?? ['start' => 0, 'end' => 0]];
        }
    }

    /**
     * Parks an approved-period rejection (403/409) as a conflict, any other API failure as an error;
     * either way the day and run continue.
     */
    private function handleWriteError(SyncRun $syncRun, PersonioApiException $personioApiException, ?string $periodId): void
    {
        $payload = null !== $periodId ? ['period_id' => $periodId] : null;
        $status = $personioApiException->getStatusCode();

        if (403 === $status || 409 === $status) {
            $syncRun->incrementCounter('conflicts');
            $this->addItem($syncRun, SyncItemKind::CONFLICT, reason: 'attendance approved in Personio; not modified', payload: $payload);

            return;
        }

        $this->logger?->warning('Personio attendance write failed', ['status' => $status, 'period' => $periodId]);
        $syncRun->incrementCounter('errors');
        $this->addItem($syncRun, SyncItemKind::ERROR, reason: substr($personioApiException->getMessage(), 0, 255), payload: $payload);
    }

    /**
     * Upserts (or removes) the day's TT-owned period record after a non-dry write set.
     *
     * The base payload is what each retained period ACTUALLY holds in Personio (per
     * {@see reconcile}), never the projection — so a write that failed keeps its old base and
     * gets retried on the next run rather than being silently recorded as in sync.
     *
     * @param list<array{start: int, end: int}> $bases
     * @param list<string>                      $resultIds
     */
    private function persistState(SyncRun $syncRun, User $user, DateTimeImmutable $day, array $bases, array $resultIds, ?PersonioAttendanceExport $state): void
    {
        // Day emptied and nothing survived: drop the record. If a conflict/error kept an id, keep
        // the record so the retained period stays tracked.
        if ([] === $resultIds) {
            if ($state instanceof PersonioAttendanceExport) {
                $this->entityManager->remove($state);
            }

            return;
        }

        if (!$state instanceof PersonioAttendanceExport) {
            $state = new PersonioAttendanceExport()->setUser($user)->setDay($day);
            $this->entityManager->persist($state);
        }

        $state->setPeriodIds($resultIds)
            ->setBasePayload($bases)
            ->setLastExportedAt($this->now())
            ->setLastSyncRun($syncRun);
    }

    /**
     * Renders a work-interval Unix timestamp as an ISO-8601 string with the server offset, matching
     * the wall-clock time the projector built it from.
     */
    private function iso(int $timestamp): string
    {
        return new DateTimeImmutable('@' . $timestamp)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(DateTimeInterface::ATOM);
    }
}
