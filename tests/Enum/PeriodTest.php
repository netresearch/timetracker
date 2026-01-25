<?php

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\Period;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Period enum.
 *
 * @internal
 */
#[CoversClass(Period::class)]
final class PeriodTest extends TestCase
{
    // ==================== Case value tests ====================

    public function testDayHasValue1(): void
    {
        self::assertSame(1, Period::DAY->value);
    }

    public function testWeekHasValue2(): void
    {
        self::assertSame(2, Period::WEEK->value);
    }

    public function testMonthHasValue3(): void
    {
        self::assertSame(3, Period::MONTH->value);
    }

    // ==================== getDisplayName tests ====================

    public function testGetDisplayNameForDay(): void
    {
        self::assertSame('Day', Period::DAY->getDisplayName());
    }

    public function testGetDisplayNameForWeek(): void
    {
        self::assertSame('Week', Period::WEEK->getDisplayName());
    }

    public function testGetDisplayNameForMonth(): void
    {
        self::assertSame('Month', Period::MONTH->getDisplayName());
    }

    // ==================== getPluralDisplayName tests ====================

    public function testGetPluralDisplayNameForDay(): void
    {
        self::assertSame('Days', Period::DAY->getPluralDisplayName());
    }

    public function testGetPluralDisplayNameForWeek(): void
    {
        self::assertSame('Weeks', Period::WEEK->getPluralDisplayName());
    }

    public function testGetPluralDisplayNameForMonth(): void
    {
        self::assertSame('Months', Period::MONTH->getPluralDisplayName());
    }

    // ==================== getDateInterval tests ====================

    public function testGetDateIntervalForDay(): void
    {
        $interval = Period::DAY->getDateInterval();

        self::assertSame(1, $interval->d);
        self::assertSame(0, $interval->m);
    }

    public function testGetDateIntervalForWeek(): void
    {
        $interval = Period::WEEK->getDateInterval();

        // PHP DateInterval P1W becomes 7 days
        self::assertSame(7, $interval->d);
    }

    public function testGetDateIntervalForMonth(): void
    {
        $interval = Period::MONTH->getDateInterval();

        self::assertSame(1, $interval->m);
        self::assertSame(0, $interval->d);
    }

    // ==================== getDateFormat tests ====================

    public function testGetDateFormatForDay(): void
    {
        self::assertSame('Y-m-d', Period::DAY->getDateFormat());
    }

    public function testGetDateFormatForWeek(): void
    {
        self::assertSame('Y-W', Period::WEEK->getDateFormat());
    }

    public function testGetDateFormatForMonth(): void
    {
        self::assertSame('Y-m', Period::MONTH->getDateFormat());
    }

    // ==================== getDisplayDateFormat tests ====================

    public function testGetDisplayDateFormatForDay(): void
    {
        self::assertSame('M j, Y', Period::DAY->getDisplayDateFormat());
    }

    public function testGetDisplayDateFormatForWeek(): void
    {
        self::assertSame('\W\e\e\k W, Y', Period::WEEK->getDisplayDateFormat());
    }

    public function testGetDisplayDateFormatForMonth(): void
    {
        self::assertSame('F Y', Period::MONTH->getDisplayDateFormat());
    }

    // ==================== getSqlFormat tests ====================

    public function testGetSqlFormatForDay(): void
    {
        self::assertSame('%Y-%m-%d', Period::DAY->getSqlFormat());
    }

    public function testGetSqlFormatForWeek(): void
    {
        self::assertSame('%Y-%u', Period::WEEK->getSqlFormat());
    }

    public function testGetSqlFormatForMonth(): void
    {
        self::assertSame('%Y-%m', Period::MONTH->getSqlFormat());
    }

    // ==================== getCacheKeySuffix tests ====================

    public function testGetCacheKeySuffixForDay(): void
    {
        self::assertSame('daily', Period::DAY->getCacheKeySuffix());
    }

    public function testGetCacheKeySuffixForWeek(): void
    {
        self::assertSame('weekly', Period::WEEK->getCacheKeySuffix());
    }

    public function testGetCacheKeySuffixForMonth(): void
    {
        self::assertSame('monthly', Period::MONTH->getCacheKeySuffix());
    }

    // ==================== getStartOfPeriod tests ====================

    public function testGetStartOfPeriodForDay(): void
    {
        $date = new DateTime('2025-01-15');

        $start = Period::DAY->getStartOfPeriod($date);

        self::assertSame('2025-01-15', $start->format('Y-m-d'));
    }

    public function testGetStartOfPeriodForWeek(): void
    {
        // Wednesday
        $date = new DateTime('2025-01-15');

        $start = Period::WEEK->getStartOfPeriod($date);

        // Monday of that week
        self::assertSame('2025-01-13', $start->format('Y-m-d'));
    }

    public function testGetStartOfPeriodForWeekWithDateTimeImmutable(): void
    {
        // Wednesday
        $date = new DateTimeImmutable('2025-01-15');

        $start = Period::WEEK->getStartOfPeriod($date);

        // Monday of that week
        self::assertSame('2025-01-13', $start->format('Y-m-d'));
    }

    public function testGetStartOfPeriodForMonth(): void
    {
        $date = new DateTime('2025-01-15');

        $start = Period::MONTH->getStartOfPeriod($date);

        self::assertSame('2025-01-01', $start->format('Y-m-d'));
    }

    // ==================== getEndOfPeriod tests ====================

    public function testGetEndOfPeriodForDay(): void
    {
        $date = new DateTime('2025-01-15');

        $end = Period::DAY->getEndOfPeriod($date);

        self::assertSame('2025-01-15', $end->format('Y-m-d'));
    }

    public function testGetEndOfPeriodForWeek(): void
    {
        // Wednesday
        $date = new DateTime('2025-01-15');

        $end = Period::WEEK->getEndOfPeriod($date);

        // Sunday of that week
        self::assertSame('2025-01-19', $end->format('Y-m-d'));
    }

    public function testGetEndOfPeriodForWeekWithDateTimeImmutable(): void
    {
        // Wednesday
        $date = new DateTimeImmutable('2025-01-15');

        $end = Period::WEEK->getEndOfPeriod($date);

        // Sunday of that week
        self::assertSame('2025-01-19', $end->format('Y-m-d'));
    }

    public function testGetEndOfPeriodForMonth(): void
    {
        $date = new DateTime('2025-01-15');

        $end = Period::MONTH->getEndOfPeriod($date);

        self::assertSame('2025-01-31', $end->format('Y-m-d'));
    }

    public function testGetEndOfPeriodForFebruary(): void
    {
        $date = new DateTime('2025-02-15');

        $end = Period::MONTH->getEndOfPeriod($date);

        self::assertSame('2025-02-28', $end->format('Y-m-d'));
    }

    public function testGetEndOfPeriodForFebruaryLeapYear(): void
    {
        // 2024 is a leap year
        $date = new DateTime('2024-02-15');

        $end = Period::MONTH->getEndOfPeriod($date);

        self::assertSame('2024-02-29', $end->format('Y-m-d'));
    }

    // ==================== all() tests ====================

    public function testAllReturnsAllCases(): void
    {
        $all = Period::all();

        self::assertCount(3, $all);
        self::assertContains(Period::DAY, $all);
        self::assertContains(Period::WEEK, $all);
        self::assertContains(Period::MONTH, $all);
    }

    // ==================== forDateRange tests ====================

    public function testForDateRangeReturnsDayForShortRange(): void
    {
        $start = new DateTime('2025-01-01');
        $end = new DateTime('2025-01-05');

        self::assertSame(Period::DAY, Period::forDateRange($start, $end));
    }

    public function testForDateRangeReturnsDayForExactlySevenDays(): void
    {
        $start = new DateTime('2025-01-01');
        $end = new DateTime('2025-01-08');

        self::assertSame(Period::DAY, Period::forDateRange($start, $end));
    }

    public function testForDateRangeReturnsWeekForMediumRange(): void
    {
        $start = new DateTime('2025-01-01');
        $end = new DateTime('2025-02-01');

        self::assertSame(Period::WEEK, Period::forDateRange($start, $end));
    }

    public function testForDateRangeReturnsWeekForExactly90Days(): void
    {
        $start = new DateTime('2025-01-01');
        $end = new DateTime('2025-04-01'); // 90 days later

        self::assertSame(Period::WEEK, Period::forDateRange($start, $end));
    }

    public function testForDateRangeReturnsMonthForLongRange(): void
    {
        $start = new DateTime('2025-01-01');
        $end = new DateTime('2025-06-01');

        self::assertSame(Period::MONTH, Period::forDateRange($start, $end));
    }

    public function testForDateRangeWorksWithDateTimeImmutable(): void
    {
        $start = new DateTimeImmutable('2025-01-01');
        $end = new DateTimeImmutable('2025-03-01');

        self::assertSame(Period::WEEK, Period::forDateRange($start, $end));
    }

    // ==================== Date format output tests ====================

    /**
     * @param array{period: Period, date: string, expected: string} $data
     */
    #[DataProvider('dateFormatProvider')]
    public function testDateFormatProducesExpectedOutput(array $data): void
    {
        $date = new DateTime($data['date']);
        $formatted = $date->format($data['period']->getDateFormat());

        self::assertSame($data['expected'], $formatted);
    }

    /**
     * @return iterable<string, array{array{period: Period, date: string, expected: string}}>
     */
    public static function dateFormatProvider(): iterable
    {
        yield 'day format' => [['period' => Period::DAY, 'date' => '2025-01-15', 'expected' => '2025-01-15']];
        yield 'week format' => [['period' => Period::WEEK, 'date' => '2025-01-15', 'expected' => '2025-03']];
        yield 'month format' => [['period' => Period::MONTH, 'date' => '2025-01-15', 'expected' => '2025-01']];
    }
}
