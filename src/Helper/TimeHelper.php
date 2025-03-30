<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Helper;

/**
 * TimeHelper provides conversions between time formats
 */
class TimeHelper
{
    /**
     *
     */
    const DAYS_PER_WEEK = 5;

    /**
     *
     */
    const HOURS_PER_DAY = 8;

    /**
     * @param $letter
     */
    public static function getMinutesByLetter($letter): int
    {
        return match ($letter) {
            'w' => self::DAYS_PER_WEEK * self::HOURS_PER_DAY * 60,
            'd' => self::HOURS_PER_DAY * 60,
            'h' => 60,
            'm' => 1,
            '' => 1,
            default => 0,
        };
    }

    /**
     * @param $readable
     */
    public static function readable2minutes($readable): int|float
    {
        if (!preg_match_all('/([0-9.,]+)([wdhm]|$)/iU', (string) $readable, $matches)) {
            return 0;
        }

        $sum = 0;
        $c = count($matches[0]);
        for ($i = 0; $i < $c; $i++) {
            $sum += (float) str_replace(',', '.', $matches[1][$i]) * self::getMinutesByLetter($matches[2][$i]);
        }

        return $sum;
    }



    /**
     * @param integer $minutes
     * @param bool    $useWeeks
     */
    public static function minutes2readable($minutes, $useWeeks = true): string
    {
        $minutes = (int) $minutes;

        if (0 >= $minutes) {
            return '0m';
        }

        $sizes = (bool) $useWeeks ? ['w', 'd', 'h'] : ['d', 'h'];

        $out = '';
        foreach ($sizes as $size) {
            $div = self::getMinutesByLetter($size);
            $factor = floor($minutes / $div);
            if (0 < $factor) {
                $out .= $factor . $size . ' ';
                $minutes -= $factor * $div;
            }
        }

        if (0  < $minutes) {
            $out .= $minutes . 'm';
        }

        return trim($out);
    }



    /**
     * Formats minutes in H:i format or days, if necessary
     *
     * @param number $duration
     * @param bool   $inDays
     */
    public static function formatDuration($duration, $inDays = false): string
    {
        $days = number_format($duration / (60*8), 2);
        $hours = floor($duration / 60);
        $minutes = floor($duration % 60);
        if ($minutes < 10) {
            $minutes = '0' . $minutes;
        }

        if ($hours < 10) {
            $hours = '0' . $hours;
        }

        $text = $hours . ':' . $minutes;
        if (($inDays)&&($days > 1.00)) {
            $text .= ' (' . $days . ' PT)';
        }

        return $text;
    }



    /**
     * Returns percent value of $amount from $sum.
     *
     * @param number $amount
     * @param number $sum
     */
    public static function formatQuota($amount, $sum): string
    {
        return number_format($sum ? ($amount * 100.00 / $sum) : 0, 2) . '%';
    }
}
