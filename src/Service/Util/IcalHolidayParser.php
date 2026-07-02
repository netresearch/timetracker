<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Util;

use function checkdate;
use function explode;
use function ksort;
use function preg_match;
use function preg_replace;
use function str_replace;
use function strcspn;
use function strlen;
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

            // Split "NAME[;params]:value" — real feeds carry parameters such as
            // DTSTART;TZID=Europe/Berlin:… or SUMMARY;LANGUAGE=de:… that a strict
            // prefix match would miss.
            [$name, $value] = $this->splitProperty($line);
            if ('DTSTART' === $name) {
                $date = $this->toIsoDate($value);
            } elseif ('SUMMARY' === $name) {
                $title = trim($value);
            }
        }

        ksort($events);

        return $events;
    }

    /**
     * Splits a content line into its property name (before the first `;` or `:`)
     * and its value (after the first unescaped `:`). Parameters are discarded.
     *
     * @return array{0: string, 1: string}
     */
    private function splitProperty(string $line): array
    {
        $colon = strcspn($line, ':');
        if ($colon === strlen($line)) {
            return ['', ''];
        }

        $namePart = substr($line, 0, $colon);
        $name = explode(';', $namePart, 2)[0];

        return [$name, substr($line, $colon + 1)];
    }

    /**
     * `YYYYMMDD[...]` => `YYYY-MM-DD`, or null when the value is not a real
     * calendar date (rejects e.g. 20260231).
     */
    private function toIsoDate(string $value): ?string
    {
        if (1 !== preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $matches)) {
            return null;
        }

        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }

        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }
}
