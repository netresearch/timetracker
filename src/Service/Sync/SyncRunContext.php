<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\ValueObject\Sync\WorklogSnapshot;

/**
 * Per-run parameters and accumulating state of one bidirectional sync run
 * (ADR-023 Phase 3), mirroring ImportRunContext's shape.
 */
final class SyncRunContext
{
    /** @var array<int, array{worklog: JiraWorkLog, snapshot: WorklogSnapshot, issueKey: string}> unmatched remote worklogs (move-detection + import pool) */
    public array $unmatchedRemote = [];

    /** @var array<string, array{user: User, day: string}> (user, day) pairs needing day-class recalculation */
    public array $affectedDays = [];

    public function __construct(
        public readonly SyncRun $syncRun,
        public readonly TicketSystem $ticketSystem,
        public readonly JiraOAuthApiService $api,
        public readonly bool $dryRun,
    ) {
    }
}
