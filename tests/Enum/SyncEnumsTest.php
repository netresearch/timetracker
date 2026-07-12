<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\SyncAction;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogField;
use App\Enum\WorklogSyncStatus;
use PHPUnit\Framework\TestCase;

final class SyncEnumsTest extends TestCase
{
    public function testSyncRunTypeValues(): void
    {
        self::assertSame(
            ['import', 'sync', 'verify', 'personio_export', 'personio_import'],
            array_column(SyncRunType::cases(), 'value'),
        );
    }

    public function testSyncRunStatusValues(): void
    {
        self::assertSame(['running', 'partial', 'completed', 'failed'], array_column(SyncRunStatus::cases(), 'value'));
    }

    public function testWorklogSyncStatusValues(): void
    {
        self::assertSame(['in_sync', 'conflict', 'orphaned'], array_column(WorklogSyncStatus::cases(), 'value'));
    }

    public function testSyncItemKindValues(): void
    {
        self::assertSame(
            ['remote_only', 'local_only', 'never_synced', 'diverged', 'local_dirty', 'remote_dirty', 'mergeable', 'conflict', 'probable_duplicate', 'unresolved_project', 'project_auto_imported', 'shadow_user_created', 'truncated', 'error'],
            array_column(SyncItemKind::cases(), 'value'),
        );
    }

    public function testSyncActionValues(): void
    {
        self::assertSame(
            ['none', 'push', 'pull', 'merge', 'conflict', 'create_local', 'remote_missing', 'diverged'],
            array_column(SyncAction::cases(), 'value'),
        );
    }

    public function testWorklogFieldValues(): void
    {
        self::assertSame(['issue_key', 'started', 'duration', 'comment'], array_column(WorklogField::cases(), 'value'));
    }
}
