<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use JsonSerializable;

use function count;

/**
 * One day of the caller's own bookings — what the tracking grid shows for the
 * day (ADR-022 Phase 2): the entries in start order plus the booked total, so
 * an agent can spot out-of-band bookings without a second call.
 */
final readonly class DaySummaryDto implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $entries
     */
    public function __construct(
        public string $date,
        public array $entries,
        public int $totalMinutes,
    ) {
    }

    /**
     * @return array{date: string, entries: list<array<string, mixed>>, count: int, total_minutes: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date,
            'entries' => $this->entries,
            'count' => count($this->entries),
            'total_minutes' => $this->totalMinutes,
        ];
    }
}
