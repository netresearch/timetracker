<?php

namespace Tests\Service\Util;

use App\Service\Util\TimeCalculationService;
use Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TimeHelperTest extends AbstractWebTestCase
{
    #[DataProvider('readable2MinutesDataProvider')]
    public function testReadable2Minutes(int $minutes, string $readable): void
    {
        $svc = new TimeCalculationService();
        $this->assertEquals($minutes, $svc->readableToMinutes($readable));
    }

    public static function readable2MinutesDataProvider(): array
    {
        return [
            [0, ''],
            [0, '0'],
            [0, '0m'],
            [2, '2m'],
            [2, '2'],
            [90, '90m'],

            [60, '1h'],
            [90, '1.5h'],
            [90, '1,5h'],
            [120, '2h'],
            [150, '2h 30m'],
            [135, '2h 15'],

            [8*60, '1d'],
            [9*60, '1,125d'],
            [16*60, '2d'],

            [16 * 60 + 120, '2d 2h'],
            [16 * 60 + 122, '2d 2h 2m'],
            [20 * 60 + 152, '2,5d 2,5h 2m'],

            [5 * 8 * 60, '1w'],
            [10 * 8 * 60, '2w'],

            [10 * 8 * 60 + 16*60, '2w 2d'],
            [10 * 8 * 60 + 16*60 + 120, '2w 2d 2h'],
            [10 * 8 * 60 + 16*60 + 122, '2w 2d 2h 2m']
        ];
    }

    #[DataProvider('minutes2ReadableDataProvider')]
    public function testMinutes2Readable(string $readable, int $minutes, bool $useWeeks= true): void
    {
        $svc = new TimeCalculationService();
        $this->assertEquals($readable, $svc->minutesToReadable($minutes, $useWeeks));
    }

    public static function minutes2ReadableDataProvider(): array
    {
        return [
            ['0m', 0],
            ['2m', 2],

            ['1h', 60],
            ['1h 30m', 90],
            ['2h', 120],

            ['1d', 8 * 60],
            ['2d', 16 * 60],

            ['2d 2h', 16 * 60 + 120],
            ['2d 2h 2m', 16 * 60 + 122],
            ['1w', 5 * 8 * 60],
            ['2w', 10 * 8 * 60],

            ['2w 2d', 10 * 8 * 60 + 16*60],
            ['2w 2d 2h', 10 * 8 * 60 + 16*60 + 120],
            ['2w 2d 2h 2m', 10 * 8 * 60 + 16*60 + 122],

            ['12d', 10 * 8 * 60 + 16*60, false],
            ['12d 2h', 10 * 8 * 60 + 16*60 + 120, false],
            ['12d 2h 2m', 10 * 8 * 60 + 16*60 + 122, false]
        ];
    }

    #[DataProvider('formatDurationDataProvider')]
    public function testFormatDuration(int|float $duration, bool $inDays, string $value): void
    {
        $svc = new TimeCalculationService();
        $this->assertEquals($value, $svc->formatDuration($duration, $inDays));
    }

    public static function formatDurationDataProvider(): array
    {
        return [
             [0, false, '00:00'],
             [0, true, '00:00'],
             [30, false, '00:30'],
             [30, true, '00:30'],
             [90, false, '01:30'],
             [90, true, '01:30'],
             [60 * 10, false, '10:00'],
             [60 * 10, true, '10:00 (1.25 PT)'],
             [60 * 8 * 42.5 + 15, false, '340:15'],
             [60 * 8 * 42.5 + 15, true, '340:15 (42.53 PT)']
        ];
    }

    #[DataProvider('dataProviderTestFormatQuota')]
    public function testFormatQuota(int|float $amount, int $sum, string $value): void
    {
        $svc = new TimeCalculationService();
        $this->assertEquals($value, $svc->formatQuota($amount, $sum));
    }

    public static function dataProviderTestFormatQuota(): array
    {
        return [
             [0, 100, '0.00%'],
             [100, 0, '0.00%'],
             [100, 100, '100.00%'],
             [45.67, 100, '45.67%']
        ];
    }

    public function testGetMinutesByLetter(): void
    {
        $svc = new TimeCalculationService();
        $this->assertEquals(0, $svc->getMinutesByLetter('f'));
        $this->assertEquals(1, $svc->getMinutesByLetter(''));
        $this->assertEquals(1, $svc->getMinutesByLetter('m'));
        $this->assertEquals(60, $svc->getMinutesByLetter('h'));
        $this->assertEquals(60 * 8, $svc->getMinutesByLetter('d'));
        $this->assertEquals(60 * 8 * 5, $svc->getMinutesByLetter('w'));
    }
}
