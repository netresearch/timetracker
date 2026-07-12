<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\ValueObject\Sync;

use App\Enum\WorklogField;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTime;
use PHPUnit\Framework\TestCase;

final class WorklogSnapshotTest extends TestCase
{
    private function snapshot(string $issueKey = 'ABC-1', int $started = 1751871600, int $minutes = 60, string $comment = '#5: Development: fixed it'): WorklogSnapshot
    {
        return new WorklogSnapshot($issueKey, $started, $minutes, $comment);
    }

    public function testEqualSnapshotsHaveEmptyDiff(): void
    {
        self::assertTrue($this->snapshot()->equals($this->snapshot()));
        self::assertSame([], $this->snapshot()->diff($this->snapshot()));
    }

    public function testStartedAtUtcIsIndependentOfTheDefaultTimezone(): void
    {
        $timestamp = 1751871600;
        $previous = date_default_timezone_get();
        // A non-UTC default would have shifted the old `new DateTime()->setTimestamp()`
        // idiom — the helper must render the same wall-clock regardless.
        date_default_timezone_set('Europe/Berlin');
        try {
            $started = $this->snapshot(started: $timestamp)->startedAtUtc();

            self::assertSame(0, $started->getOffset());
            self::assertSame(gmdate('Y-m-d H:i:s', $timestamp), $started->format('Y-m-d H:i:s'));
            // Proof the default TZ would otherwise have shifted it: the Berlin
            // render of the same instant differs from the UTC wall-clock.
            $berlinRender = new DateTime()->setTimestamp($timestamp);
            self::assertNotSame($berlinRender->format('H:i'), $started->format('H:i'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testDiffListsEveryChangedField(): void
    {
        $diff = $this->snapshot()->diff($this->snapshot(issueKey: 'ABC-2', minutes: 90));

        self::assertSame([WorklogField::ISSUE_KEY, WorklogField::DURATION], $diff);
    }

    public function testDiffDetectsStartedAndComment(): void
    {
        $diff = $this->snapshot()->diff($this->snapshot(started: 1751875200, comment: 'other'));

        self::assertSame([WorklogField::STARTED, WorklogField::COMMENT], $diff);
    }

    public function testArrayRoundTrip(): void
    {
        $snapshot = $this->snapshot();

        self::assertTrue(WorklogSnapshot::fromArray($snapshot->toArray())->equals($snapshot));
        self::assertSame(
            ['issue_key' => 'ABC-1', 'started_ts' => 1751871600, 'duration_minutes' => 60, 'comment' => '#5: Development: fixed it'],
            $snapshot->toArray(),
        );
    }
}
