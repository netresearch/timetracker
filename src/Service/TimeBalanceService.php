<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\Response\PeriodBalanceDto;
use App\Dto\Response\TimeBalanceDto;
use App\Entity\Contract;
use App\Entity\User;
use App\Enum\BalanceStatus;
use App\Enum\Period;
use App\Repository\ContractRepository;
use App\Repository\EntryRepository;
use App\Service\Util\ExpectedWorkTimeCalculator;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

use function abs;
use function assert;
use function intdiv;
use function is_string;
use function max;
use function min;
use function sprintf;
use function substr;

/**
 * The authenticated user's worked-vs-target balance for today/week/month
 * (ADR-021 Phase 5 / ADR-022 — the get_time_balance MCP tool, the
 * GET /api/v2/time-balance endpoint, and the log_time enrichment).
 *
 * For each period it reports IST (worked minutes), SOLL for the whole period and
 * SOLL through today ("so far"), the difference, and a status the consumer can
 * act on: `behind` when IST < SOLL-so-far, `over` when IST > SOLL-total, else
 * `ok`. SOLL comes from the user's contracts minus public holidays, the same
 * source as /getTimeSummary and the /ui/month balance.
 */
final readonly class TimeBalanceService
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private EntryRepository $entryRepository,
        private ExpectedWorkTimeCalculator $expectedWorkTimeCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function forUser(User $user): TimeBalanceDto
    {
        $userId = (int) $user->getId();
        $today = $this->clock->today();

        $weekStart = $this->modify($today, 'monday this week');
        $weekEnd = $this->modify($today, 'sunday this week');
        $monthStart = $this->modify($today, 'first day of this month');
        $monthEnd = $this->modify($today, 'last day of this month');

        $contracts = $this->contracts($user);
        $holidays = $this->holidayDates(min($weekStart, $monthStart), max($weekEnd, $monthEnd));

        $periods = [
            'today' => $this->period($this->entryRepository->getWorkByUser($userId, Period::DAY), $contracts, $holidays, $today, $today, $today),
            'week' => $this->period($this->entryRepository->getWorkByUser($userId, Period::WEEK), $contracts, $holidays, $weekStart, $weekEnd, $today),
            'month' => $this->period($this->entryRepository->getWorkByUser($userId, Period::MONTH), $contracts, $holidays, $monthStart, $monthEnd, $today),
        ];

        $warnings = [];
        foreach ($periods as $label => $period) {
            if (BalanceStatus::Behind === $period->status) {
                $warnings[] = sprintf('%s: behind target by %s (worked %s, expected %s so far).', $label, $this->hm(-$period->diff), $this->hm($period->ist), $this->hm($period->sollSoFar));
            } elseif (BalanceStatus::Over === $period->status) {
                $warnings[] = sprintf('%s: over target (worked %s, target %s).', $label, $this->hm($period->ist), $this->hm($period->sollTotal));
            }
        }

        return new TimeBalanceDto($periods['today'], $periods['week'], $periods['month'], $warnings);
    }

    /** Minutes as "Hh Mm" (e.g. 95 → "1h 35m"). */
    private function hm(int $minutes): string
    {
        $minutes = abs($minutes);

        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @param array{duration: int, count: int} $work
     * @param Contract[]                       $contracts
     * @param array<string, true>              $holidays
     */
    private function period(array $work, array $contracts, array $holidays, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $today): PeriodBalanceDto
    {
        $ist = $work['duration'];
        $sollTotal = $this->expectedWorkTimeCalculator->minutesForRange($contracts, $holidays, $start, $end);
        $sollSoFar = $this->expectedWorkTimeCalculator->minutesForRange($contracts, $holidays, $start, min($today, $end));

        $status = BalanceStatus::Ok;
        if ($ist > $sollTotal && $sollTotal > 0) {
            $status = BalanceStatus::Over;
        } elseif ($ist < $sollSoFar) {
            $status = BalanceStatus::Behind;
        }

        return new PeriodBalanceDto(
            ist: $ist,
            sollTotal: $sollTotal,
            sollSoFar: $sollSoFar,
            diff: $ist - $sollSoFar,
            status: $status,
        );
    }

    /**
     * @return Contract[]
     */
    private function contracts(User $user): array
    {
        $repository = $this->managerRegistry->getRepository(Contract::class);
        assert($repository instanceof ContractRepository);

        /** @var Contract[] $contracts */
        $contracts = $repository->findBy(['user' => $user], ['start' => 'DESC']);

        return $contracts;
    }

    /**
     * @return array<string, true>
     */
    private function holidayDates(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $connection = $this->managerRegistry->getConnection();
        assert($connection instanceof Connection);

        $rows = $connection->fetchFirstColumn(
            'SELECT day FROM holidays WHERE day BETWEEN ? AND ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')],
        );

        $holidays = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $holidays[substr($row, 0, 10)] = true;
            }
        }

        return $holidays;
    }

    private function modify(DateTimeImmutable $date, string $modifier): DateTimeImmutable
    {
        $result = $date->modify($modifier);

        // The modifiers used here are all well-defined; the guard only satisfies
        // the analyser (DateTimeImmutable::modify is typed to allow false).
        return false !== $result ? $result : $date;
    }
}
