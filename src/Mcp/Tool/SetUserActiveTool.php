<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Response\UserDto;
use App\Mcp\AdminEntityResolver;
use App\Mcp\ScopeGuard;
use App\Service\AdminOnboardingService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * MCP admin tool: activate or offboard (deactivate) a user (ADR-022 Phase 3).
 */
final readonly class SetUserActiveTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private AdminEntityResolver $adminEntityResolver,
        private AdminOnboardingService $adminOnboardingService,
    ) {
    }

    /**
     * Activate or deactivate (offboard) a user account. A deactivated account
     * cannot log in and its tokens stop working; its bookings stay. Requires
     * an administrator account and the users:write scope.
     *
     * @throws ToolCallException on an unknown user
     *
     * @return array<string, mixed> the updated user
     */
    #[McpTool(name: 'set_user_active', description: 'Activate or deactivate (offboard) a user account (admin only).')]
    public function setUserActive(
        #[Schema(description: 'Username or numeric id.')]
        string $user,
        #[Schema(description: 'true to activate, false to offboard.')]
        bool $active,
    ): array {
        $actingUser = $this->scopeGuard->requireAdminScope('users:write');

        $entity = $this->adminEntityResolver->user($user);
        // Lockout guard: an admin must not deactivate their own account.
        if (!$active && $entity->getId() === $actingUser->getId()) {
            throw new ToolCallException('You cannot deactivate your own account.');
        }
        $dto = $this->adminOnboardingService->setUserActive((int) $entity->getId(), $active);
        if (!$dto instanceof UserDto) {
            throw new ToolCallException('The user could not be updated.');
        }

        return $dto->jsonSerialize();
    }
}
