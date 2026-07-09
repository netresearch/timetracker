<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Type of a worklog sync run (ADR-023).
 */
enum SyncRunType: string
{
    case IMPORT = 'import';
    case SYNC = 'sync';
    case VERIFY = 'verify';
}
