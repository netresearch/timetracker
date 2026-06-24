<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Util;

use App\Entity\Contract;
use DateTimeInterface;

/**
 * Resolves a user's expected ("Soll") working hours for a given weekday, from
 * their contract or a 5×8h default (8h Mon–Fri, 0 at the weekend) when no
 * contract covers the day.
 *
 * Shared by /getContractHours (the /ui/month per-weekday Soll) and
 * /interpretation/time (the per-day Soll markers on the "Effort by day" chart)
 * so the fallback stays identical in both places.
 */
final class ContractHoursResolver
{
    /** Default daily hours on a weekday (Mon–Fri) when no contract covers the day. */
    private const float DEFAULT_WEEKDAY_HOURS = 8.0;

    /**
     * Expected hours for a weekday index (0 = Sunday … 6 = Saturday, matching
     * JS Date.getDay() and PHP date('w')). Without a contract the default is a
     * standard 5×8h week: 8h on Mon–Fri and 0 on the weekend.
     */
    public function weekdayHours(?Contract $contract, int $weekday): float
    {
        if ($contract instanceof Contract) {
            return (float) match ($weekday) {
                0 => $contract->getHours0(),
                1 => $contract->getHours1(),
                2 => $contract->getHours2(),
                3 => $contract->getHours3(),
                4 => $contract->getHours4(),
                5 => $contract->getHours5(),
                default => $contract->getHours6(),
            };
        }

        return $weekday >= 1 && $weekday <= 5 ? self::DEFAULT_WEEKDAY_HOURS : 0.0;
    }

    /**
     * The contract valid on $date from a pre-loaded, start-descending list, or
     * null. Mirrors ContractRepository::findValidContract (start <= date, and
     * end open or >= date, most recent start wins) without a per-day query —
     * the caller loads the user's contracts once and resolves each day in PHP.
     *
     * @param Contract[] $contracts ordered by contract.start DESC
     */
    public function validContract(array $contracts, DateTimeInterface $date): ?Contract
    {
        foreach ($contracts as $contract) {
            $end = $contract->getEnd();
            if ($contract->getStart() <= $date && (!$end instanceof DateTimeInterface || $end >= $date)) {
                return $contract;
            }
        }

        return null;
    }
}
