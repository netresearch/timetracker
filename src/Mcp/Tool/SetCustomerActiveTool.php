<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Response\CustomerDto;
use App\Mcp\AdminEntityResolver;
use App\Mcp\ScopeGuard;
use App\Service\AdminOnboardingService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * MCP admin tool: activate or offboard (deactivate) a customer (ADR-022
 * Phase 3).
 */
final readonly class SetCustomerActiveTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private AdminEntityResolver $adminEntityResolver,
        private AdminOnboardingService $adminOnboardingService,
    ) {
    }

    /**
     * Activate or deactivate (offboard) a customer. A deactivated customer is
     * no longer bookable; its projects and entries stay. Requires an
     * administrator account and the customers:write scope.
     *
     * @throws ToolCallException on an unknown customer
     *
     * @return array<string, mixed> the updated customer
     */
    #[McpTool(name: 'set_customer_active', description: 'Activate or deactivate (offboard) a customer (admin only).')]
    public function setCustomerActive(
        #[Schema(description: 'Customer name or numeric id.')]
        string $customer,
        #[Schema(description: 'true to activate, false to offboard.')]
        bool $active,
    ): array {
        $this->scopeGuard->requireAdminScope('customers:write');

        $entity = $this->adminEntityResolver->customer($customer);
        $dto = $this->adminOnboardingService->setCustomerActive((int) $entity->getId(), $active);
        if (!$dto instanceof CustomerDto) {
            throw new ToolCallException('The customer could not be updated.');
        }

        return $dto->jsonSerialize();
    }
}
