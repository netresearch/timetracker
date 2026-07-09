<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Team;
use App\Mcp\ScopeGuard;
use App\Repository\TeamRepository;
use Mcp\Capability\Attribute\McpTool;

use function array_map;
use function array_values;

/**
 * MCP tool: list teams (ADR-022 Phase 4).
 */
final readonly class ListTeamsTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private TeamRepository $teamRepository,
    ) {
    }

    /**
     * List teams with id, name and the lead user's id. Use a team id with
     * `onboard_customer` / `onboard_user` / `save_team`.
     *
     * @return array{teams: list<array{id: int, name: string, lead_user_id: int|null}>}
     */
    #[McpTool(name: 'list_teams', description: 'List teams (id, name, lead user).')]
    public function listTeams(): array
    {
        $this->scopeGuard->requireScope('teams:read');

        return ['teams' => array_values(array_map(
            static fn (Team $team): array => [
                'id' => (int) $team->getId(),
                'name' => (string) $team->getName(),
                'lead_user_id' => $team->getLeadUser()?->getId(),
            ],
            $this->teamRepository->findAll(),
        ))];
    }
}
