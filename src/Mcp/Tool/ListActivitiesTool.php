<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ScopeGuard;
use App\Repository\ActivityRepository;
use Mcp\Capability\Attribute\McpTool;

use function array_map;
use function array_values;

/**
 * MCP tool: list the activities an entry can be booked against (ADR-021 Phase 5).
 */
final readonly class ListActivitiesTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private ActivityRepository $activityRepository,
    ) {
    }

    /**
     * List the activities available for time entries (id, name, whether a ticket
     * is required). Use an activity's name or id with `log_time`.
     *
     * The list is wrapped in an object — MCP structuredContent must be a JSON
     * object at the top level, never a bare array (#573, ADR-022 §4).
     *
     * @return array{activities: list<array{id: int, name: string, needs_ticket: bool}>}
     */
    #[McpTool(name: 'list_activities', description: 'List the activities a time entry can be booked against.')]
    public function listActivities(): array
    {
        $this->scopeGuard->requireScope('activities:read');

        return ['activities' => array_values(array_map(
            static fn (array $row): array => [
                'id' => $row['activity']['id'],
                'name' => $row['activity']['name'],
                'needs_ticket' => $row['activity']['needsTicket'],
            ],
            $this->activityRepository->getActivities(),
        ))];
    }
}
