<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle status of a worklog sync run (ADR-023).
 */
enum SyncRunStatus: string
{
    case RUNNING = 'running';
    case PARTIAL = 'partial';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
