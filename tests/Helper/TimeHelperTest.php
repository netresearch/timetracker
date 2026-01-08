<?php

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Service\Util\TimeCalculationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class TimeHelperTest extends TestCase
{
    #[DataProvider('provideReadable2MinutesCases')]
    public function testReadable2Minutes(int $minutes, string $readable): void
    {
        $timeCalculationService = new TimeCalculationService();
        self::assertSame($minutes, (int) $timeCalculationService->readableToMinutes($readable));
    }

    /**
     * @return iterable<array{int, string}>
     */
    public static function provideReadable2MinutesCases(): iterable
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

    #[DataProvider('provideMinutes2ReadableCases')]
    public function testMinutes2Readable(string $readable, int $minutes, bool $useWeeks = true): void
    {
        $timeCalculationService = new TimeCalculationService();
        self::assertSame($readable, $timeCalculationService->minutesToReadable($minutes, $useWeeks));
    }

    /**
     * @return iterable<array{string, int, bool}|array{string, int}>
     */
    public static function provideMinutes2ReadableCases(): iterable
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

    #[DataProvider('provideFormatDurationCases')]
    public function testFormatDuration(int|float $duration, bool $inDays, string $value): void
    {
        $timeCalculationService = new TimeCalculationService();
        self::assertSame($value, $timeCalculationService->formatDuration($duration, $inDays));
    }

    /**
     * @return iterable<array{int|float, bool, string}>
     */
    public static function provideFormatDurationCases(): iterable
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

    #[DataProvider('provideFormatQuotaCases')]
    public function testFormatQuota(int|float $amount, int $sum, string $value): void
    {
        $timeCalculationService = new TimeCalculationService();
        self::assertSame($value, $timeCalculationService->formatQuota($amount, $sum));
    }

    /**
     * @return iterable<array{int|float, int, string}>
     */
    public static function provideFormatQuotaCases(): iterable
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
        $timeCalculationService = new TimeCalculationService();
        self::assertSame(0, $timeCalculationService->getMinutesByLetter('f'));
        self::assertSame(1, $timeCalculationService->getMinutesByLetter(''));
        self::assertSame(1, $timeCalculationService->getMinutesByLetter('m'));
        self::assertSame(60, $timeCalculationService->getMinutesByLetter('h'));
        self::assertSame(60 * 8, $timeCalculationService->getMinutesByLetter('d'));
        self::assertSame(60 * 8 * 5, $timeCalculationService->getMinutesByLetter('w'));
    }
}