<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Personio;

use App\Entity\Entry;
use App\Service\Personio\AttendanceProjector;
use App\ValueObject\Personio\WorkInterval;
use DateTime;
use PHPUnit\Framework\TestCase;

final class AttendanceProjectorTest extends TestCase
{
    private AttendanceProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new AttendanceProjector();
    }

    private function entryStub(string $day, string $start, string $end): Entry
    {
        $entry = self::createStub(Entry::class);
        $entry->method('getDay')->willReturn(new DateTime($day));
        $entry->method('getStart')->willReturn(new DateTime('1970-01-01 ' . $start));
        $entry->method('getEnd')->willReturn(new DateTime('1970-01-01 ' . $end));

        return $entry;
    }

    private function ts(string $dateTime): int
    {
        return new DateTime($dateTime)->getTimestamp();
    }

    /**
     * @param list<WorkInterval> $intervals
     *
     * @return list<array{start: int, end: int}>
     */
    private function toArrays(array $intervals): array
    {
        return array_map(static fn (WorkInterval $i): array => $i->toArray(), $intervals);
    }

    public function testSingleEntryProducesOneInterval(): void
    {
        $result = $this->projector->project([
            $this->entryStub('2026-07-01', '09:00:00', '10:00:00'),
        ]);

        self::assertSame(
            [['start' => $this->ts('2026-07-01 09:00:00'), 'end' => $this->ts('2026-07-01 10:00:00')]],
            $this->toArrays($result),
        );
    }

    public function testTwoSegmentsWithGapArePreserved(): void
    {
        $result = $this->projector->project([
            $this->entryStub('2026-07-01', '09:00:00', '12:30:00'),
            $this->entryStub('2026-07-01', '13:00:00', '17:30:00'),
        ]);

        self::assertSame(
            [
                ['start' => $this->ts('2026-07-01 09:00:00'), 'end' => $this->ts('2026-07-01 12:30:00')],
                ['start' => $this->ts('2026-07-01 13:00:00'), 'end' => $this->ts('2026-07-01 17:30:00')],
            ],
            $this->toArrays($result),
        );
    }

    public function testOverlappingIntervalsMergeWithoutDoubleCount(): void
    {
        $result = $this->projector->project([
            $this->entryStub('2026-07-01', '09:00:00', '11:00:00'),
            $this->entryStub('2026-07-01', '10:30:00', '12:00:00'),
        ]);

        self::assertSame(
            [['start' => $this->ts('2026-07-01 09:00:00'), 'end' => $this->ts('2026-07-01 12:00:00')]],
            $this->toArrays($result),
        );
    }

    public function testTouchingIntervalsMerge(): void
    {
        $result = $this->projector->project([
            $this->entryStub('2026-07-01', '09:00:00', '10:00:00'),
            $this->entryStub('2026-07-01', '10:00:00', '11:00:00'),
        ]);

        self::assertSame(
            [['start' => $this->ts('2026-07-01 09:00:00'), 'end' => $this->ts('2026-07-01 11:00:00')]],
            $this->toArrays($result),
        );
    }

    public function testOutOfOrderInputIsSorted(): void
    {
        $result = $this->projector->project([
            $this->entryStub('2026-07-01', '13:00:00', '17:30:00'),
            $this->entryStub('2026-07-01', '09:00:00', '12:30:00'),
        ]);

        self::assertSame(
            [
                ['start' => $this->ts('2026-07-01 09:00:00'), 'end' => $this->ts('2026-07-01 12:30:00')],
                ['start' => $this->ts('2026-07-01 13:00:00'), 'end' => $this->ts('2026-07-01 17:30:00')],
            ],
            $this->toArrays($result),
        );
    }

    public function testZeroDurationEntryIsSkipped(): void
    {
        $result = $this->projector->project([
            $this->entryStub('2026-07-01', '09:00:00', '09:00:00'),
            $this->entryStub('2026-07-01', '10:00:00', '11:00:00'),
        ]);

        self::assertSame(
            [['start' => $this->ts('2026-07-01 10:00:00'), 'end' => $this->ts('2026-07-01 11:00:00')]],
            $this->toArrays($result),
        );
    }

    public function testEmptyInputProducesEmptyList(): void
    {
        self::assertSame([], $this->projector->project([]));
    }

    public function testWorkIntervalEquals(): void
    {
        $interval = new WorkInterval(100, 200);

        self::assertTrue($interval->equals(new WorkInterval(100, 200)));
        self::assertFalse($interval->equals(new WorkInterval(100, 201)));
        self::assertFalse($interval->equals(new WorkInterval(101, 200)));
    }
}
