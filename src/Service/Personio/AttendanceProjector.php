<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Personio;

use App\Entity\Entry;
use App\ValueObject\Personio\WorkInterval;
use DateTime;
use DateTimeInterface;

/**
 * Projects a day's TT entries into overlap-free worked segments (ADR-024 §3).
 * Each entry becomes a [start, end] timestamp pair built from its day plus its
 * start/end time-of-day (server TZ, same idiom as EntryWorklogProjector); the
 * segments are sorted by start and overlapping or touching ones are merged, so
 * the gaps left between the returned intervals are exactly the breaks Personio
 * derives. A day thus maps to one WORK period per returned interval.
 */
class AttendanceProjector
{
    /**
     * @param list<Entry> $entries
     *
     * @return list<WorkInterval>
     */
    public function project(array $entries): array
    {
        $intervals = [];
        foreach ($entries as $entry) {
            $start = $this->timestampFor($entry->getDay(), $entry->getStart());
            $end = $this->timestampFor($entry->getDay(), $entry->getEnd());

            if ($end <= $start) {
                continue;
            }

            $intervals[] = [$start, $end];
        }

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $this->merge($intervals);
    }

    /**
     * @param list<array{0: int, 1: int}> $intervals
     *
     * @return list<WorkInterval>
     */
    private function merge(array $intervals): array
    {
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = array_key_last($merged);
            if (null !== $last && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return array_map(
            static fn (array $interval): WorkInterval => new WorkInterval($interval[0], $interval[1]),
            $merged,
        );
    }

    private function timestampFor(DateTimeInterface $day, DateTimeInterface $time): int
    {
        $dateTime = DateTime::createFromInterface($day);
        $dateTime->setTime(
            (int) $time->format('H'),
            (int) $time->format('i'),
        );

        return $dateTime->getTimestamp();
    }
}
