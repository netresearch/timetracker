<?php declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\TimeHelper;
use PHPUnit\Framework\TestCase;

class TimeHelperTest extends TestCase
{
    /**
     * @dataProvider readable2MinutesDataProvider
     */
    public function testReadable2Minutes($minutes, $readable): void
    {
        static::assertSame($minutes, TimeHelper::readable2minutes($readable));
    }

    public function readable2MinutesDataProvider()
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

            [8 * 60, '1d'],
            [9 * 60, '1,125d'],
            [16 * 60, '2d'],

            [16 * 60 + 120, '2d 2h'],
            [16 * 60 + 122, '2d 2h 2m'],
            [20 * 60 + 152, '2,5d 2,5h 2m'],

            [5 * 8 * 60, '1w'],
            [10 * 8 * 60, '2w'],

            [10 * 8 * 60 + 16 * 60, '2w 2d'],
            [10 * 8 * 60 + 16 * 60 + 120, '2w 2d 2h'],
            [10 * 8 * 60 + 16 * 60 + 122, '2w 2d 2h 2m'],
        ];
    }

    /**
     * @dataProvider minutes2ReadableDataProvider
     */
    public function testMinutes2Readable($readable, $minutes, $useWeeks= true): void
    {
        static::assertSame($readable, TimeHelper::minutes2readable($minutes, $useWeeks));
    }

    public function minutes2ReadableDataProvider()
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

            ['2w 2d', 10 * 8 * 60 + 16 * 60],
            ['2w 2d 2h', 10 * 8 * 60 + 16 * 60 + 120],
            ['2w 2d 2h 2m', 10 * 8 * 60 + 16 * 60 + 122],

            ['12d', 10 * 8 * 60 + 16 * 60, false],
            ['12d 2h', 10 * 8 * 60 + 16 * 60 + 120, false],
            ['12d 2h 2m', 10 * 8 * 60 + 16 * 60 + 122, false],
        ];
    }

    /**
     * @dataProvider formatDurationDataProvider
     */
    public function testFormatDuration($duration, $inDays, $value): void
    {
        static::assertSame($value, TimeHelper::formatDuration($duration, $inDays));
    }

    public function formatDurationDataProvider()
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
             [60 * 8 * 42.5 + 15, true, '340:15 (42.53 PT)'],
        ];
    }

    /**
     * @dataProvider dataProviderTestFormatQuota
     */
    public function testFormatQuota($amount, $sum, $value): void
    {
        static::assertSame($value, TimeHelper::formatQuota($amount, $sum));
    }

    public function dataProviderTestFormatQuota()
    {
        return [
             [0, 100, '0.00%'],
             [100, 0, '0.00%'],
             [100, 100, '100.00%'],
             [45.67, 100, '45.67%'],
        ];
    }

    public function testGetMinutesByLetter(): void
    {
        static::assertSame(0, TimeHelper::getMinutesByLetter('f'));
        static::assertSame(1, TimeHelper::getMinutesByLetter(''));
        static::assertSame(1, TimeHelper::getMinutesByLetter('m'));
        static::assertSame(60, TimeHelper::getMinutesByLetter('h'));
        static::assertSame(60 * 8, TimeHelper::getMinutesByLetter('d'));
        static::assertSame(60 * 8 * 5, TimeHelper::getMinutesByLetter('w'));
    }
}
