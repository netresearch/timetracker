<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Entity\Activity;
use App\Entity\Entry;
use App\Service\Sync\EntryWorklogProjector;
use App\Service\Sync\WorklogCommentCodec;
use DateTime;
use PHPUnit\Framework\TestCase;

final class EntryWorklogProjectorTest extends TestCase
{
    private EntryWorklogProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new EntryWorklogProjector(new WorklogCommentCodec());
    }

    private function entryStub(): Entry
    {
        $activity = self::createStub(Activity::class);
        $activity->method('getName')->willReturn('Development');

        $entry = self::createStub(Entry::class);
        $entry->method('getId')->willReturn(42);
        $entry->method('getTicket')->willReturn('ABC-1');
        $entry->method('getDay')->willReturn(new DateTime('2026-07-08'));
        $entry->method('getStart')->willReturn(new DateTime('1970-01-01 09:30:00'));
        $entry->method('getDuration')->willReturn(90);
        $entry->method('getDescription')->willReturn('fixed it');
        $entry->method('getActivity')->willReturn($activity);

        return $entry;
    }

    public function testProjectionUsesDayPlusStartTime(): void
    {
        $snapshot = $this->projector->project($this->entryStub());

        self::assertSame('ABC-1', $snapshot->issueKey);
        self::assertSame(new DateTime('2026-07-08 09:30:00')->getTimestamp(), $snapshot->startedTimestamp);
        self::assertSame(90, $snapshot->durationMinutes);
        self::assertSame('#42: Development: fixed it', $snapshot->comment);
    }
}
