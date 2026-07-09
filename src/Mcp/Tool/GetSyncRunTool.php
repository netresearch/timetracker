<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Response\SyncRunDto;
use App\Entity\SyncRun;
use App\Mcp\ScopeGuard;
use App\Repository\SyncRunRepository;
use App\Service\Sync\SyncRunAuthorization;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * MCP tool: one worklog sync run with its per-worklog findings (ADR-023 §6).
 * Non-admins see only runs they triggered themselves.
 */
final readonly class GetSyncRunTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private SyncRunRepository $syncRunRepository,
        private SyncRunAuthorization $syncRunAuthorization,
    ) {
    }

    /**
     * One worklog sync run — status, counters, and the per-worklog findings
     * (imported, skipped, conflicted, …). Non-admins can only inspect runs
     * they triggered themselves.
     *
     * @throws ToolCallException on missing scope, unknown run, or a foreign
     *                           run without an administrator account
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_sync_run', description: 'One worklog sync run with its counters and per-worklog findings. Non-admins see only their own runs.')]
    public function getSyncRun(
        #[Schema(description: 'The run id, as returned by sync_jira_worklogs.', minimum: 1)]
        int $runId,
    ): array {
        $user = $this->scopeGuard->requireScope('sync:read');

        $syncRun = $this->syncRunRepository->find($runId);
        if (!$syncRun instanceof SyncRun) {
            throw new ToolCallException('Run not found.');
        }

        if (!$this->syncRunAuthorization->canSeeRun($user, false, $syncRun)) {
            // Foreign runs need an administrator account.
            $this->scopeGuard->requireAdminScope('sync:read');
        }

        return SyncRunDto::fromEntity($syncRun)->jsonSerialize();
    }
}
