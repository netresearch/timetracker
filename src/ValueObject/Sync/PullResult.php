<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ValueObject\Sync;

/**
 * Outcome of applying remote worklog changes to an entry (ADR-023 §2 pull).
 */
final readonly class PullResult
{
    /**
     * @param list<string> $affectedDays Y-m-d days whose totals changed (before/after when the entry moved)
     */
    public function __construct(
        public bool $applied,
        public string $reason = '',
        public array $affectedDays = [],
    ) {
    }
}
