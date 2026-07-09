<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ValueObject\Sync;

/**
 * Outcome of resolving a parked worklog sync state (ADR-023 §2 conflict resolution).
 */
final readonly class ResolutionResult
{
    /**
     * @param string $action one of pushed_local|pulled_remote|recreated_local|deleted_local, '' when unresolved
     */
    public function __construct(
        public bool $resolved,
        public string $action,
        public string $reason = '',
    ) {
    }
}
