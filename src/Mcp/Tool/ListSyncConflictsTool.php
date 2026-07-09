<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Response\SyncConflictDto;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Mcp\ScopeGuard;
use App\Repository\WorklogSyncStateRepository;
use Doctrine\Persistence\ManagerRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

use function array_map;
use function count;

/**
 * MCP tool: parked worklog sync states — conflicts and orphans awaiting a
 * winner (ADR-023 §6). Non-admins are forced to their own entries; admins
 * see all and may filter by username.
 */
final readonly class ListSyncConflictsTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private WorklogSyncStateRepository $worklogSyncStateRepository,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * Parked worklog sync states (CONFLICT or ORPHANED) with the local entry,
     * the lease base, and the stored remote snapshot — everything needed to
     * pick a winner via resolve_sync_conflict. Non-admins always get their
     * own entries; the user filter is admin-only.
     *
     * @throws ToolCallException on missing scope or an unknown filter user
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_sync_conflicts', description: 'Parked worklog sync conflicts/orphans awaiting resolution. Non-admins see their own entries; admins may filter by username.')]
    public function listSyncConflicts(
        #[Schema(description: 'Filter by username (admin only; ignored for non-admins).')]
        ?string $user = null,
    ): array {
        $actor = $this->scopeGuard->requireScope('sync:read');

        $filter = $actor;
        if ($this->scopeGuard->isAdmin()) {
            $filter = null;
            if (null !== $user && '' !== $user) {
                $filter = $this->managerRegistry->getRepository(User::class)->findOneBy(['username' => $user]);
                if (!$filter instanceof User) {
                    throw new ToolCallException('Unknown user.');
                }
            }
        }

        $conflicts = array_map(
            static fn (WorklogSyncState $state): array => SyncConflictDto::fromEntity($state)->jsonSerialize(),
            $this->worklogSyncStateRepository->findParked($filter),
        );

        return ['conflicts' => $conflicts, 'count' => count($conflicts)];
    }
}
