<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\ScopeGuard;
use App\Repository\UserRepository;
use Mcp\Capability\Attribute\McpTool;

use function array_map;
use function array_values;

/**
 * MCP tool: list user accounts (ADR-022 Phase 4). Admin-only.
 */
final readonly class ListUsersTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * List user accounts with id, username, abbreviation, type and active flag.
     * Requires an administrator account and the users:read scope.
     *
     * @return array{users: list<array{id: int, username: string, abbr: string, type: string, active: bool}>}
     */
    #[McpTool(name: 'list_users', description: 'List user accounts (admin only).')]
    public function listUsers(): array
    {
        $this->scopeGuard->requireAdminScope('users:read');

        return ['users' => array_values(array_map(
            static fn (User $user): array => [
                'id' => (int) $user->getId(),
                'username' => (string) $user->getUsername(),
                'abbr' => (string) $user->getAbbr(),
                'type' => $user->getType()->value,
                'active' => $user->getActive(),
            ],
            $this->userRepository->findAll(),
        ))];
    }
}
