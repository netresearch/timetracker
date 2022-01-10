<?php declare(strict_types=1);
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Helper;

/**
 * TimeHelper provides conversions between time formats.
 */
class TimeHelper
{
    final public const DAYS_PER_WEEK = 5;

    final public const HOURS_PER_DAY = 8;

    public static function getMinutesByLetter(string $letter): int
    {
        return match ($letter) {
            'w'     => self::DAYS_PER_WEEK * self::HOURS_PER_DAY * 60,
            'd'     => self::HOURS_PER_DAY * 60,
            'h'     => 60,
            'm'     => 1,
            ''      => 1,
            default => 0,
        };
    }

    public static function readable2minutes(string $readable): int
    {
        if (!preg_match_all('/([0-9.,]+)([wdhm]|$)/iU', $readable, $matches)) {
            return 0;
        }

        $sum = 0;
        $c   = is_countable($matches[0]) ? \count($matches[0]) : 0;
        for ($i = 0; $i < $c; ++$i) {
            $sum += (int) (str_replace(',', '.', $matches[1][$i]) * self::getMinutesByLetter($matches[2][$i]));
        }

        return $sum;
    }

    /**
     * @param int  $minutes
     * @param bool $useWeeks
     *
     * @return string
     */
    public static function minutes2readable(int $minutes, bool $useWeeks = true): string
    {
        $minutes = (int) $minutes;

        if (0 >= $minutes) {
            return '0m';
        }

        if ((bool) $useWeeks) {
            $sizes = ['w', 'd', 'h'];
        } else {
            $sizes = ['d', 'h'];
        }

        $out = '';
        foreach ($sizes as $letter) {
            $div    = self::getMinutesByLetter($letter);
            $factor = floor($minutes / $div);
            if (0 < $factor) {
                $out .= $factor.$letter.' ';
                $minutes -= $factor * $div;
            }
        }

        if (0 < $minutes) {
            $out .= $minutes.'m';
        }

        return trim($out);
    }

    /**
     * Formats minutes in H:i format or days, if necessary.
     */
    public static function formatDuration(int|float $duration, bool $inDays = false): string
    {
        $days    = number_format($duration / (60 * 8), 2);
        $hours   = floor($duration / 60);
        $minutes = floor($duration % 60);
        if ($minutes < 10) {
            $minutes = '0'.$minutes;
        }
        if ($hours < 10) {
            $hours = '0'.$hours;
        }

        $text = $hours.':'.$minutes;
        if (($inDays) && ($days > 1.00)) {
            $text .= ' ('.$days.' PT)';
        }

        return $text;
    }

    /**
     * Returns percent value of $amount from $sum.
     */
    public static function formatQuota(int|float $amount, int|float $sum): string
    {
        return number_format($sum ? ($amount * 100.00 / $sum) : 0, 2).'%';
    }
}
