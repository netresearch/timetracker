<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\WorklogSyncState;
use App\Mcp\ScopeGuard;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Sync\ConflictResolutionService;
use App\Service\Sync\SyncRunAuthorization;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * MCP tool: resolve a parked worklog sync state by picking a winner
 * (ADR-023 §6): local wins via a forced lease-era write, remote wins by
 * pulling the live remote — or deleting the local entry when the remote is
 * gone. Non-admins may only resolve conflicts on their own entries.
 */
final readonly class ResolveSyncConflictTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private WorklogSyncStateRepository $worklogSyncStateRepository,
        private SyncRunAuthorization $syncRunAuthorization,
        private ConflictResolutionService $conflictResolutionService,
    ) {
    }

    /**
     * Pick a winner for a parked conflict from list_sync_conflicts:
     * winner=local force-pushes the timetracker entry to Jira (recreating the
     * worklog if it vanished), winner=remote pulls the live Jira worklog into
     * the entry — or accepts the remote deletion by removing the entry.
     *
     * @throws ToolCallException on missing scope, unknown conflict, a foreign
     *                           conflict without an administrator account, or
     *                           a failed resolution
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'resolve_sync_conflict', description: 'Resolve a parked worklog sync conflict: winner=local force-pushes the entry to Jira, winner=remote pulls the live worklog (or accepts its deletion).')]
    public function resolveSyncConflict(
        #[Schema(description: 'The conflict id, as returned by list_sync_conflicts.', minimum: 1)]
        int $conflictId,
        #[Schema(description: 'Which side wins: "local" or "remote".')]
        string $winner,
    ): array {
        $user = $this->scopeGuard->requireScope('sync:write');

        $state = $this->worklogSyncStateRepository->findParkedById($conflictId);
        if (!$state instanceof WorklogSyncState) {
            throw new ToolCallException('Conflict not found.');
        }

        if (!$this->syncRunAuthorization->canResolve($user, false, $state)) {
            // Foreign conflicts need an administrator account.
            $this->scopeGuard->requireAdminScope('sync:write');
        }

        $resolutionResult = $this->conflictResolutionService->resolve($state, $winner, $user);
        if (!$resolutionResult->resolved) {
            throw new ToolCallException($resolutionResult->reason);
        }

        return ['resolved' => true, 'action' => $resolutionResult->action, 'conflict_id' => $conflictId];
    }
}
