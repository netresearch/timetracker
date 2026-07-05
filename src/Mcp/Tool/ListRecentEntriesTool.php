<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Entry;
use App\Mcp\ScopeGuard;
use App\Repository\EntryRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

use function array_map;
use function max;
use function min;

/**
 * MCP tool: list the caller's own recent time entries (ADR-021 Phase 5).
 */
final readonly class ListRecentEntriesTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private EntryRepository $entryRepository,
    ) {
    }

    /**
     * List the authenticated user's own time entries from the last N days
     * (default 7, capped at 90), oldest first. Each entry includes its id, date,
     * start/end, duration, ticket, project/activity ids and description.
     *
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'list_recent_entries', description: "List the authenticated user's own recent time entries.")]
    public function listRecentEntries(
        #[Schema(description: 'How many days back to include (1–90).', minimum: 1, maximum: 90)]
        int $days = 7,
    ): array {
        $user = $this->scopeGuard->requireScope('entries:read');

        $days = max(1, min(90, $days));

        return array_map(
            static fn (Entry $entry): array => $entry->toArray(),
            $this->entryRepository->getEntriesByUser($user, $days, false),
        );
    }
}
