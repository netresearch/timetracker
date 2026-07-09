<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Decision produced by the reconciliation matrix (ADR-023 §2).
 */
enum SyncAction: string
{
    case NONE = 'none';
    case PUSH = 'push';
    case PULL = 'pull';
    case MERGE = 'merge';
    case CONFLICT = 'conflict';
    case CREATE_LOCAL = 'create_local';
    case REMOTE_MISSING = 'remote_missing';
    case DIVERGED = 'diverged';
}
