<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\Response\DaySummaryDto;
use App\Entity\User;
use App\Repository\EntryRepository;
use InvalidArgumentException;

use function checkdate;
use function preg_match;
use function sprintf;

/**
 * The caller's own bookings for one day (default: today) — the day list the
 * tracking UI shows, exposed to GET /api/v2/day, the get_day MCP tool and the
 * log_time enrichment (ADR-022 Phase 2).
 */
final readonly class DaySummaryService
{
    public function __construct(
        private EntryRepository $entryRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $date 'Y-m-d'; null for today
     *
     * @throws InvalidArgumentException when $date is not a valid Y-m-d date
     */
    public function forUser(User $user, ?string $date = null): DaySummaryDto
    {
        $day = $date ?? $this->clock->today()->format('Y-m-d');
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s"; use YYYY-MM-DD.', $day));
        }

        $entries = [];
        $totalMinutes = 0;
        foreach ($this->entryRepository->findByDay((int) $user->getId(), $day) as $entry) {
            $entries[] = $entry->toArray();
            $totalMinutes += $entry->getDuration();
        }

        return new DaySummaryDto(date: $day, entries: $entries, totalMinutes: $totalMinutes);
    }
}
