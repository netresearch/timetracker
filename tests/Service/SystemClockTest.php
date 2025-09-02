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
    public function testNowReturnsDateTimeImmutable(): void
    {
        $systemClock = new SystemClock();
        $now = $systemClock->now();

        self::assertInstanceOf(DateTimeImmutable::class, $now);
    }

    public function testTodayReturnsMidnight(): void
    {
        $systemClock = new SystemClock();
        $today = $systemClock->today();

        self::assertInstanceOf(DateTimeImmutable::class, $today);
        self::assertSame('00:00:00', $today->format('H:i:s'));
    }
}
