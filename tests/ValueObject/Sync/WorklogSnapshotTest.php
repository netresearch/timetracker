<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\ValueObject\Sync;

use App\Enum\WorklogField;
use App\ValueObject\Sync\WorklogSnapshot;
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
