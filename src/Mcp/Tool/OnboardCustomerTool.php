<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\CustomerOnboardDto;
use App\Mcp\ScopeGuard;
use App\Service\AdminOnboardingService;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

use function array_map;
use function array_values;

/**
 * MCP admin tool: onboard a customer (ADR-022 Phase 3).
 */
final readonly class OnboardCustomerTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private AdminOnboardingService $adminOnboardingService,
    ) {
    }

    /**
     * Create (onboard) a new, active customer. A non-global customer needs at
     * least one team to be visible. Requires an administrator account and the
     * customers:write scope.
     *
     * @param list<int> $teamIds
     *
     * @throws ToolCallException on a validation failure
     *
     * @return array<string, mixed> the created customer
     */
    #[McpTool(name: 'onboard_customer', description: 'Onboard a new customer (admin only).')]
    public function onboardCustomer(
        #[Schema(description: 'Customer name (at least 3 characters).')]
        string $name,
        #[Schema(description: 'Whether the customer is bookable by every team.')]
        bool $global = false,
        #[Schema(description: 'Team ids granting visibility; required unless global.')]
        array $teamIds = [],
    ): array {
        $this->scopeGuard->requireAdminScope('customers:write');

        try {
            return $this->adminOnboardingService->onboardCustomer(new CustomerOnboardDto(
                name: $name,
                global: $global,
                team_ids: array_values(array_map(intval(...), $teamIds)),
            ))->jsonSerialize();
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ToolCallException($invalidArgumentException->getMessage(), $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }
    }
}
