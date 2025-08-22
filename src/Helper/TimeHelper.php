<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * Legacy static facade for time calculations; delegates to TimeCalculationService.
 */
class TimeHelper
{
    public static function getMinutesByLetter(string $unit): int
    {
        return match (strtolower($unit)) {
            '' => 1,
            'm' => 1,
            'h' => 60,
            'd' => 480,
            'w' => 2400,
            default => 0,
        };
    }
    public const DAYS_PER_WEEK = 5;

    public const HOURS_PER_DAY = 8;

    public static function readable2minutes(string $readable): int|float
    {
        return (new \App\Service\Util\TimeCalculationService())->readableToMinutes($readable);
    }

    public static function minutes2readable(int $minutes, bool $useWeeks = true): string
    {
        return (new \App\Service\Util\TimeCalculationService())->minutesToReadable($minutes, $useWeeks);
    }

    public static function formatDuration(int|float $duration, bool $inDays = false): string
    {
        return (new \App\Service\Util\TimeCalculationService())->formatDuration($duration, $inDays);
    }

    public static function formatQuota(int|float $amount, int|float $sum): string
    {
        return (new \App\Service\Util\TimeCalculationService())->formatQuota($amount, $sum);
    }
}
