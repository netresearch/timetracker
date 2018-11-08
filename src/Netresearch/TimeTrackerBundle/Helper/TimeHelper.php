<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace Netresearch\TimeTrackerBundle\Helper;

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
     * @return float|int
     */
    public static function getMinutesByLetter($letter) {
        switch ($letter) {
        case 'w': return self::DAYS_PER_WEEK * self::HOURS_PER_DAY * 60;
        case 'd': return self::HOURS_PER_DAY * 60;
        case 'h': return 60;
        case 'm': return 1;
        case '': return 1;
        default: return 0;
        }
    }

    /**
     * @param $readable
     * @return float|int
     */
    public static function readable2minutes($readable)
    {
        if (!preg_match_all('/([0-9.,]+)([wdhm]|$)/iU', $readable, $matches)) {
            return 0;
        }

        $sum = 0;
        $c = count($matches[0]);
        for ($i = 0; $i < $c; $i++) {
            $sum += (float) str_replace(',','.',$matches[1][$i]) * self::getMinutesByLetter($matches[2][$i]);
        }
        return $sum;
    }



    /**
     * @param integer $minutes
     * @param bool    $useWeeks
     * @return string
     */
    public static function minutes2readable($minutes, $useWeeks = true)
    {
        $minutes = (int) $minutes;

        if (0 >= $minutes)
            return '0m';

        if ((bool) $useWeeks) {
            $sizes = ['w', 'd', 'h'];
        } else {
            $sizes = ['d', 'h'];
        }

        $out = '';
        foreach ($sizes as $letter) {
            $div = self::getMinutesByLetter($letter);
            $factor = floor($minutes / $div);
            if (0 < $factor) {
                $out .= $factor . $letter . ' ';
                $minutes -= $factor * $div;
            }
        }

        if (0  < $minutes)
            $out .= $minutes . 'm';

        return trim($out);
    }



    /**
     * Formats minutes in H:i format or days, if necessary
     *
     * @param number $duration
     * @param bool   $inDays
     * @return string
     */
    public static function formatDuration($duration, $inDays = false)
    {
        $days = number_format($duration / (60*8), 2);
        $hours = floor($duration / 60);
        $minutes = floor($duration % 60);
        if ($minutes < 10)
            $minutes = '0' . $minutes;
        if ($hours < 10)
            $hours = '0' . $hours;

        $text = $hours . ':' . $minutes;
        if (($inDays)&&($days > 1.00))
            $text .= ' (' . $days . ' PT)';

        return $text;
    }



    /**
     * Returns percent value of $amount from $sum.
     *
     * @param number $amount
     * @param number $sum
     * @return string
     */
    public static function formatQuota($amount, $sum)
    {
        return number_format($sum ? ($amount * 100.00 / $sum) : 0, 2) . '%';
    }

}

