<?php

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Service\Util\TimeCalculationService;
use PHPUnit\Framework\TestCase;

class TimeCalculationServiceTest extends TestCase
{
    private TimeCalculationService $timeCalculationService;

    protected function setUp(): void
    {
        $this->timeCalculationService = new TimeCalculationService();
    }

    public function testGetMinutesByLetter(): void
    {
        $this->assertSame(5 * 8 * 60, $this->timeCalculationService->getMinutesByLetter('w'));
        $this->assertSame(8 * 60, $this->timeCalculationService->getMinutesByLetter('d'));
        $this->assertSame(60, $this->timeCalculationService->getMinutesByLetter('h'));
        $this->assertSame(1, $this->timeCalculationService->getMinutesByLetter('m'));
        $this->assertSame(1, $this->timeCalculationService->getMinutesByLetter(''));
        $this->assertSame(0, $this->timeCalculationService->getMinutesByLetter('x'));
    }

    public function testReadableToMinutes(): void
    {
        $this->assertSame(0, $this->timeCalculationService->readableToMinutes('invalid'));
        $this->assertSame(60.0, $this->timeCalculationService->readableToMinutes('1h'));
        $this->assertSame(90.0, $this->timeCalculationService->readableToMinutes('1h 30m'));
        $this->assertSame(5 * 8 * 60.0, $this->timeCalculationService->readableToMinutes('1w'));
        $this->assertSame(8 * 60.0, $this->timeCalculationService->readableToMinutes('1d'));
        $this->assertSame(135.0, $this->timeCalculationService->readableToMinutes('2,25h'));
        $this->assertSame(12 * 60.0 + 15.0, $this->timeCalculationService->readableToMinutes('12h 15m'));
    }

    public function testMinutesToReadable(): void
    {
        $this->assertSame('0m', $this->timeCalculationService->minutesToReadable(0));
        $this->assertSame('30m', $this->timeCalculationService->minutesToReadable(30));
        $this->assertSame('1h 30m', $this->timeCalculationService->minutesToReadable(90));
        $this->assertSame('1d', $this->timeCalculationService->minutesToReadable(8 * 60));
        $this->assertSame('1w', $this->timeCalculationService->minutesToReadable(5 * 8 * 60));
        $this->assertSame('1d 30m', $this->timeCalculationService->minutesToReadable(8 * 60 + 30));

        // Without weeks
        $this->assertSame('5d', $this->timeCalculationService->minutesToReadable(5 * 8 * 60, false));
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('00:00', $this->timeCalculationService->formatDuration(0));
        $this->assertSame('00:30', $this->timeCalculationService->formatDuration(30));
        $this->assertSame('01:30', $this->timeCalculationService->formatDuration(90));

        // In days appendix when requested and > 1 day
        $this->assertSame('16:00 (2.00 PT)', $this->timeCalculationService->formatDuration(16 * 60, true));
    }

    public function testFormatQuota(): void
    {
        $this->assertSame('0.00%', $this->timeCalculationService->formatQuota(0, 0));
        $this->assertSame('50.00%', $this->timeCalculationService->formatQuota(50, 100));
        $this->assertSame('33.33%', $this->timeCalculationService->formatQuota(1, 3));
    }
}
