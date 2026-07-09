<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Service\Sync\RemoteWorklogNormalizer;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteWorklogNormalizerTest extends TestCase
{
    private RemoteWorklogNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RemoteWorklogNormalizer();
    }

    public function testNormalizeParsesOffsetTimestampAndSeconds(): void
    {
        $workLog = new JiraWorkLog(id: 7, comment: " work done\r\n", started: '2026-07-08T09:30:00.000+0200', timeSpentSeconds: 5400);

        $snapshot = $this->normalizer->normalize($workLog, 'ABC-1');

        self::assertSame('ABC-1', $snapshot->issueKey);
        self::assertSame(new DateTimeImmutable('2026-07-08T09:30:00.000+0200')->getTimestamp(), $snapshot->startedTimestamp);
        self::assertSame(90, $snapshot->durationMinutes);
        self::assertSame('work done', $snapshot->comment);
    }

    public function testSecondsRoundHalfUpToMinutes(): void
    {
        $up = new JiraWorkLog(id: 1, started: '2026-07-08T09:00:00.000+0000', timeSpentSeconds: 90);
        $down = new JiraWorkLog(id: 2, started: '2026-07-08T09:00:00.000+0000', timeSpentSeconds: 89);

        self::assertSame(2, $this->normalizer->normalize($up, 'A-1')->durationMinutes);
        self::assertSame(1, $this->normalizer->normalize($down, 'A-1')->durationMinutes);
    }

    public function testMissingStartedThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->normalize(new JiraWorkLog(id: 1, timeSpentSeconds: 60), 'A-1');
    }

    public function testNullCommentBecomesEmptyString(): void
    {
        $workLog = new JiraWorkLog(id: 1, started: '2026-07-08T09:00:00.000+0000', timeSpentSeconds: 60);

        self::assertSame('', $this->normalizer->normalize($workLog, 'A-1')->comment);
    }
}
