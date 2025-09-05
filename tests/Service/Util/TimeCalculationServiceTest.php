<?php

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Service\Util\TimeCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class TimeCalculationServiceTest extends TestCase
{
    private TimeCalculationService $timeCalculationService;

    public function setUp(): void
    {
        $this->timeCalculationService = new TimeCalculationService();
    }

    public function testGetMinutesByLetter(): void
    {
        self::assertSame(5 * 8 * 60, $this->timeCalculationService->getMinutesByLetter('w'));
        self::assertSame(8 * 60, $this->timeCalculationService->getMinutesByLetter('d'));
        self::assertSame(60, $this->timeCalculationService->getMinutesByLetter('h'));
        self::assertSame(1, $this->timeCalculationService->getMinutesByLetter('m'));
        self::assertSame(1, $this->timeCalculationService->getMinutesByLetter(''));
        self::assertSame(0, $this->timeCalculationService->getMinutesByLetter('x'));
    }

    public function testReadableToMinutes(): void
    {
        self::assertSame(0, $this->timeCalculationService->readableToMinutes('invalid'));
        self::assertSame(60.0, $this->timeCalculationService->readableToMinutes('1h'));
        self::assertSame(90.0, $this->timeCalculationService->readableToMinutes('1h 30m'));
        self::assertSame(5 * 8 * 60.0, $this->timeCalculationService->readableToMinutes('1w'));
        self::assertSame(8 * 60.0, $this->timeCalculationService->readableToMinutes('1d'));
        self::assertSame(135.0, $this->timeCalculationService->readableToMinutes('2,25h'));
        self::assertSame(12 * 60.0 + 15.0, $this->timeCalculationService->readableToMinutes('12h 15m'));
    }

    public function testMinutesToReadable(): void
    {
        self::assertSame('0m', $this->timeCalculationService->minutesToReadable(0));
        self::assertSame('30m', $this->timeCalculationService->minutesToReadable(30));
        self::assertSame('1h 30m', $this->timeCalculationService->minutesToReadable(90));
        self::assertSame('1d', $this->timeCalculationService->minutesToReadable(8 * 60));
        self::assertSame('1w', $this->timeCalculationService->minutesToReadable(5 * 8 * 60));
        self::assertSame('1d 30m', $this->timeCalculationService->minutesToReadable(8 * 60 + 30));

        // Without weeks
        self::assertSame('5d', $this->timeCalculationService->minutesToReadable(5 * 8 * 60, false));
    }

    public function testFormatDuration(): void
    {
        self::assertSame('00:00', $this->timeCalculationService->formatDuration(0));
        self::assertSame('00:30', $this->timeCalculationService->formatDuration(30));
        self::assertSame('01:30', $this->timeCalculationService->formatDuration(90));

        // In days appendix when requested and > 1 day
        self::assertSame('16:00 (2.00 PT)', $this->timeCalculationService->formatDuration(16 * 60, true));
    }

    public function testFormatQuota(): void
    {
        self::assertSame('0.00%', $this->timeCalculationService->formatQuota(0, 0));
        self::assertSame('50.00%', $this->timeCalculationService->formatQuota(50, 100));
        self::assertSame('33.33%', $this->timeCalculationService->formatQuota(1, 3));
    }
}
