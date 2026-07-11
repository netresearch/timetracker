<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ValueObject\Personio;

/**
 * A single overlap-free worked segment as Unix timestamps (ADR-024 §3). One WORK
 * attendance period maps to one interval; the gaps between intervals are the breaks
 * Personio derives in its day view.
 */
final readonly class WorkInterval
{
    public function __construct(
        public int $startTimestamp,
        public int $endTimestamp,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->startTimestamp === $other->startTimestamp
            && $this->endTimestamp === $other->endTimestamp;
    }

    /**
     * @return array{start: int, end: int}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->startTimestamp,
            'end' => $this->endTimestamp,
        ];
    }
}
