<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Util;

use App\Entity\Contract;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Sums a user's expected ("Soll") working minutes over an inclusive date range,
 * dropping to 0 on public holidays — the same rule the /ui/month "expected"
 * column and the "Effort by day" chart use, so the sidebar balance matches them.
 *
 * The caller pre-loads the user's contracts (start DESC) and the holiday dates
 * once; each day is then resolved in PHP via {@see ContractHoursResolver}.
 */
final readonly class ExpectedWorkTimeCalculator
{
    public function __construct(private ContractHoursResolver $contractHoursResolver)
    {
    }

    /**
     * Expected minutes for every day in [$start, $end] (both inclusive),
     * excluding public holidays. Returns 0 when $end precedes $start.
     *
     * @param Contract[]          $contracts    ordered by contract.start DESC
     * @param array<string, true> $holidayDates 'Y-m-d' => true public-holiday lookup
     */
    public function minutesForRange(
        array $contracts,
        array $holidayDates,
        DateTimeInterface $start,
        DateTimeInterface $end,
    ): int {
        // Normalise to date-only midnights so a stray time component can't drop the
        // last day; INCLUDE_END_DATE then makes the range inclusive of $end without
        // a modify('+1 day') that could theoretically return false.
        $from = DateTimeImmutable::createFromInterface($start)->setTime(0, 0);
        $to = DateTimeImmutable::createFromInterface($end)->setTime(0, 0);
        if ($to < $from) {
            return 0;
        }

        $hours = 0.0;
        $period = new DatePeriod($from, new DateInterval('P1D'), $to, DatePeriod::INCLUDE_END_DATE);
        foreach ($period as $day) {
            if (isset($holidayDates[$day->format('Y-m-d')])) {
                continue;
            }

            $hours += $this->contractHoursResolver->weekdayHours(
                $this->contractHoursResolver->validContract($contracts, $day),
                (int) $day->format('w'),
            );
        }

        return (int) round($hours * 60);
    }
}
