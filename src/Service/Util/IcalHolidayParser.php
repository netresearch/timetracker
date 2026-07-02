<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Util;

use function ksort;
use function preg_match;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Minimal iCalendar (RFC 5545) event extractor for holiday feeds.
 *
 * Deliberately not a full iCal implementation: holiday feeds are flat lists
 * of all-day VEVENTs. Handles line folding (continuation lines starting with
 * space/tab), `DTSTART;VALUE=DATE:` and datetime `DTSTART:` forms, and
 * `SUMMARY:`. Ported from the Mogic fork (TIM-135) with v5 typing.
 */
final readonly class IcalHolidayParser
{
    /**
     * @return array<string, string> ISO date (Y-m-d) => holiday name, sorted by date
     */
    public function parse(string $icalContent): array
    {
        // Normalize line endings, then unfold: a CRLF followed by space/tab
        // continues the previous line (RFC 5545 §3.1).
        $icalContent = str_replace(["\r\n", "\r"], "\n", $icalContent);
        $icalContent = (string) preg_replace('/\n[ \t]/', '', $icalContent);

        $events = [];
        $inEvent = false;
        $date = null;
        $title = null;

        foreach (explode("\n", $icalContent) as $line) {
            $line = trim($line);

            if ('BEGIN:VEVENT' === $line) {
                $inEvent = true;
                $date = null;
                $title = null;
                continue;
            }

            if (!$inEvent) {
                continue;
            }

            if ('END:VEVENT' === $line) {
                $inEvent = false;
                if (null !== $date && null !== $title && '' !== $title) {
                    $events[$date] = $title;
                }

                continue;
            }

            if (str_starts_with($line, 'DTSTART;VALUE=DATE:')) {
                $date = $this->toIsoDate(substr($line, 19));
            } elseif (str_starts_with($line, 'DTSTART:')) {
                $date = $this->toIsoDate(substr($line, 8));
            } elseif (str_starts_with($line, 'SUMMARY:')) {
                $title = trim(substr($line, 8));
            }
        }

        ksort($events);

        return $events;
    }

    /**
     * `YYYYMMDD[...]` => `YYYY-MM-DD`, or null when the value is not a date.
     */
    private function toIsoDate(string $value): ?string
    {
        if (1 !== preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $matches)) {
            return null;
        }

        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }
}
