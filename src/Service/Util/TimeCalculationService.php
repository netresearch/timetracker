<?php

declare(strict_types=1);

namespace App\Service\Util;

use function count;
use function sprintf;

class TimeCalculationService
{
    public const int DAYS_PER_WEEK = 5;

    public const int HOURS_PER_DAY = 8;

    public function getMinutesByLetter(string $letter): int
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

    public function readableToMinutes(string $readable): int|float
    {
        if (0 === preg_match_all('/([0-9.,]+)([wdhm]|$)/iU', $readable, $matches)) {
            return 0;
        }

        $sum = 0;
        $c = count($matches[0]);
        for ($i = 0; $i < $c; ++$i) {
            $sum += (float) str_replace(',', '.', $matches[1][$i]) * $this->getMinutesByLetter($matches[2][$i]);
        }

        return $sum;
    }

    /**
     * Parse a human readable duration and round down to full minutes.
     */
    public function readableToFullMinutes(string $readable): int
    {
        $minutes = $this->readableToMinutes($readable);

        return (int) floor($minutes);
    }

    public function minutesToReadable(int|float $minutes, bool $useWeeks = true): string
    {
        $minutes = (int) $minutes;

        if ($minutes <= 0) {
            return '0m';
        }

        $sizes = $useWeeks ? ['w', 'd', 'h'] : ['d', 'h'];

        $out = '';
        foreach ($sizes as $size) {
            $div = $this->getMinutesByLetter($size);
            $factor = (int) floor($minutes / $div);
            if ($factor > 0) {
                $out .= $factor . $size . ' ';
                $minutes -= $factor * $div;
            }
        }

        if ($minutes > 0) {
            $out .= $minutes . 'm';
        }

        return trim($out);
    }

    public function formatDuration(int|float $duration, bool $inDays = false): string
    {
        $days = number_format($duration / (60 * self::HOURS_PER_DAY), 2);
        $hours = (int) floor($duration / 60);
        $minutes = (int) floor($duration % 60);
        if ($minutes < 10) {
            $minutes = (int) ('0' . $minutes);
        }

        if ($hours < 10) {
            $hours = (int) ('0' . $hours);
        }

        $text = sprintf('%02d:%02d', $hours, $minutes);
        if ($inDays && (float) $days > 1.00) {
            $text .= ' (' . $days . ' PT)';
        }

        return $text;
    }

    public function formatQuota(int|float $amount, int|float $sum): string
    {
        return number_format(0.0 !== $sum && 0 !== $sum ? ($amount * 100.00 / $sum) : 0, 2) . '%';
    }
}
