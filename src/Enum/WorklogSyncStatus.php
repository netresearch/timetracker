<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Durable per-entry sync state (ADR-023). Dirty flags are computed, not stored.
 */
enum WorklogSyncStatus: string
{
    case IN_SYNC = 'in_sync';
    case CONFLICT = 'conflict';
    case ORPHANED = 'orphaned';
}
