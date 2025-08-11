<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * Legacy static facade for time calculations; delegates to TimeCalculationService.
 */
class TimeHelper
{
    public const DAYS_PER_WEEK = 5;
    public const HOURS_PER_DAY = 8;

    public static function getMinutesByLetter($letter): int
    {
        return (new \App\Service\Util\TimeCalculationService())->getMinutesByLetter((string) $letter);
    }

    public static function readable2minutes($readable): int|float
    {
        return (new \App\Service\Util\TimeCalculationService())->readableToMinutes((string) $readable);
    }

    public static function minutes2readable($minutes, $useWeeks = true): string
    {
        return (new \App\Service\Util\TimeCalculationService())->minutesToReadable((int) $minutes, (bool) $useWeeks);
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
