<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Outcome of a lease-checked worklog push (ADR-023 §1).
 */
enum WriteOutcome: string
{
    case WRITTEN = 'written';
    case LEASE_LOST = 'lease_lost';
    case REMOTE_MISSING = 'remote_missing';
    case SKIPPED = 'skipped';
}
