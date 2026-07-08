<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\UserOnboardDto;
use App\Mcp\ScopeGuard;
use App\Service\AdminOnboardingService;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

use function array_map;
use function array_values;

/**
 * MCP admin tool: onboard a user (ADR-022 Phase 3).
 */
final readonly class OnboardUserTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private AdminOnboardingService $adminOnboardingService,
    ) {
    }

    /**
     * Create (onboard) a new, active user account. The account authenticates
     * against the directory (no local password is set). Every user needs at
     * least one team. Requires an administrator account and the users:write
     * scope.
     *
     * @param list<int> $teamIds
     *
     * @throws ToolCallException on a validation failure
     *
     * @return array<string, mixed> the created user
     */
    #[McpTool(name: 'onboard_user', description: 'Onboard a new user account (admin only).')]
    public function onboardUser(
        #[Schema(description: 'Login name (at least 3 characters), e.g. "jane.doe".')]
        string $username,
        #[Schema(description: 'Short abbreviation shown in listings, e.g. "JDO".')]
        string $abbr,
        #[Schema(description: 'User type.', enum: ['USER', 'DEV', 'PL', 'ADMIN'])]
        string $type = 'DEV',
        #[Schema(description: 'UI locale.', enum: ['de', 'en', 'es', 'fr', 'ru'])]
        string $locale = 'de',
        #[Schema(description: 'Team ids the user belongs to (at least one).')]
        array $teamIds = [],
    ): array {
        $this->scopeGuard->requireAdminScope('users:write');

        try {
            return $this->adminOnboardingService->onboardUser(new UserOnboardDto(
                username: $username,
                abbr: $abbr,
                type: $type,
                locale: $locale,
                team_ids: array_values(array_map(intval(...), $teamIds)),
            ))->jsonSerialize();
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ToolCallException($invalidArgumentException->getMessage(), $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }
    }
}
