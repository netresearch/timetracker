<?php

declare(strict_types=1);

namespace App\Enum;

use DateInterval;
use DateTime;
use DateTimeInterface;

/**
 * Time period enumeration for reporting and filtering.
 */
enum Period: int
{
    case DAY = 1;
    case WEEK = 2;
    case MONTH = 3;

    /**
     * Get display name for this period.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::DAY => 'Day',
            self::WEEK => 'Week',
            self::MONTH => 'Month',
        };
    }

    /**
     * Get plural display name for this period.
     */
    public function getPluralDisplayName(): string
    {
        return match ($this) {
            self::DAY => 'Days',
            self::WEEK => 'Weeks',
            self::MONTH => 'Months',
        };
    }

    /**
     * Get DateInterval for this period.
     */
    public function getDateInterval(): DateInterval
    {
        return match ($this) {
            self::DAY => new DateInterval('P1D'),
            self::WEEK => new DateInterval('P1W'),
            self::MONTH => new DateInterval('P1M'),
        };
    }

    /**
     * Get date format string suitable for this period.
     */
    public function getDateFormat(): string
    {
        return match ($this) {
            self::DAY => 'Y-m-d',
            self::WEEK => 'Y-W',  // Year and week number
            self::MONTH => 'Y-m',
        };
    }

    /**
     * Get display date format for this period.
     */
    public function getDisplayDateFormat(): string
    {
        return match ($this) {
            self::DAY => 'M j, Y',
            self::WEEK => '\W\e\e\k W, Y',
            self::MONTH => 'F Y',
        };
    }

    /**
     * Calculate the start of period for given date.
     */
    public function getStartOfPeriod(DateTimeInterface $date): DateTime
    {
        $start = new DateTime($date->format('Y-m-d'));

        return match ($this) {
            self::DAY => $start,
            self::WEEK => $start->modify('monday this week'),
            self::MONTH => $start->modify('first day of this month'),
        };
    }

    /**
     * Calculate the end of period for given date.
     */
    public function getEndOfPeriod(DateTimeInterface $date): DateTime
    {
        $end = new DateTime($date->format('Y-m-d'));

        return match ($this) {
            self::DAY => $end,
            self::WEEK => $end->modify('sunday this week'),
            self::MONTH => $end->modify('last day of this month'),
        };
    }

    /**
     * Get SQL format string for this period.
     */
    public function getSqlFormat(): string
    {
        return match ($this) {
            self::DAY => '%Y-%m-%d',
            self::WEEK => '%Y-%u',   // Year and week
            self::MONTH => '%Y-%m',
        };
    }

    /**
     * Get cache key suffix for this period.
     */
    public function getCacheKeySuffix(): string
    {
        return match ($this) {
            self::DAY => 'daily',
            self::WEEK => 'weekly',
            self::MONTH => 'monthly',
        };
    }

    /**
     * Get all available periods.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Get appropriate period for date range span.
     */
    public static function forDateRange(DateTimeInterface $start, DateTimeInterface $end): self
    {
        $diff = $start->diff($end);
        $totalDays = $diff->days;

        return match (true) {
            $totalDays <= 7 => self::DAY,
            $totalDays <= 90 => self::WEEK,
            default => self::MONTH,
        };
    }
}
