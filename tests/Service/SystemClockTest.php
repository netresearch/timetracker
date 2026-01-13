<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\SystemClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SystemClockTest extends TestCase
{
    public function testNowReturnsCurrentTime(): void
    {
        $systemClock = new SystemClock();
        $before = new DateTimeImmutable();
        $now = $systemClock->now();
        $after = new DateTimeImmutable();

        // Verify the returned time is within the expected range
        self::assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }

    public function testTodayReturnsMidnight(): void
    {
        $systemClock = new SystemClock();
        $today = $systemClock->today();
        $expectedDate = (new DateTimeImmutable('today midnight'))->format('Y-m-d');

        // Verify we get today's date at midnight
        self::assertSame($expectedDate, $today->format('Y-m-d'));
        self::assertSame('00:00:00', $today->format('H:i:s'));
    }
}
