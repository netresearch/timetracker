<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\SystemClock;
use PHPUnit\Framework\TestCase;

class SystemClockTest extends TestCase
{
    public function testNowReturnsDateTimeImmutable(): void
    {
        $systemClock = new SystemClock();
        $now = $systemClock->now();

        $this->assertInstanceOf(\DateTimeImmutable::class, $now);
    }

    public function testTodayReturnsMidnight(): void
    {
        $systemClock = new SystemClock();
        $today = $systemClock->today();

        $this->assertInstanceOf(\DateTimeImmutable::class, $today);
        $this->assertSame('00:00:00', $today->format('H:i:s'));
    }
}
