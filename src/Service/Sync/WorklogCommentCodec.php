<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

/**
 * Reproduces the worklog comment the production push writes
 * (JiraOAuthApiService::getTicketSystemWorkLogComment): "#<entryId>: <activity>: <description>".
 * The diff MUST use this exact projection or no worklog would ever compare as in-sync.
 */
class WorklogCommentCodec
{
    public function encode(?int $entryId, ?string $activityName, string $description): string
    {
        $activity = $activityName ?? 'no activity specified';

        if ('' === $description || '0' === $description) {
            $description = 'no description given';
        }

        return '#' . ($entryId ?? 0) . ': ' . $activity . ': ' . $description;
    }

    public static function normalize(string $comment): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $comment));
    }

    /**
     * Inverse of encode() for pulls: extracts the description from a TT-format
     * comment ("#<id>: <activity>: <description>"); non-TT comments pass through.
     */
    public static function decode(string $comment): string
    {
        $normalized = self::normalize($comment);

        if (1 === preg_match('/^#\d+: [^:]*?: (?<description>.*)$/s', $normalized, $matches)) {
            return $matches['description'];
        }

        return $normalized;
    }
}
