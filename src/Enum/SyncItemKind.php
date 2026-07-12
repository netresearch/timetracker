<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Kind of a noteworthy sync-run item (ADR-023). Routine successes are counters, not items.
 */
enum SyncItemKind: string
{
    case REMOTE_ONLY = 'remote_only';
    case LOCAL_ONLY = 'local_only';
    case NEVER_SYNCED = 'never_synced';
    case DIVERGED = 'diverged';
    case LOCAL_DIRTY = 'local_dirty';
    case REMOTE_DIRTY = 'remote_dirty';
    case MERGEABLE = 'mergeable';
    case CONFLICT = 'conflict';
    case PROBABLE_DUPLICATE = 'probable_duplicate';
    case UNRESOLVED_PROJECT = 'unresolved_project';
    case PROJECT_AUTO_IMPORTED = 'project_auto_imported';
    case SHADOW_USER_CREATED = 'shadow_user_created';
    case TRUNCATED = 'truncated';
    case ERROR = 'error';
}
