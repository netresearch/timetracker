<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Customer;
use App\Mcp\ScopeGuard;
use App\Repository\CustomerRepository;
use Mcp\Capability\Attribute\McpTool;

use function array_map;
use function array_values;

/**
 * MCP tool: list customers (ADR-022 Phase 4).
 */
final readonly class ListCustomersTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private CustomerRepository $customerRepository,
    ) {
    }

    /**
     * List customers with id, name, and active/global flags. Use a customer's
     * name or id with `onboard_project` / `list_projects`.
     *
     * @return array{customers: list<array{id: int, name: string, active: bool, global: bool}>}
     */
    #[McpTool(name: 'list_customers', description: 'List customers (id, name, active, global).')]
    public function listCustomers(): array
    {
        $this->scopeGuard->requireScope('customers:read');

        return ['customers' => array_values(array_map(
            static fn (Customer $customer): array => [
                'id' => (int) $customer->getId(),
                'name' => (string) $customer->getName(),
                'active' => (bool) $customer->getActive(),
                'global' => (bool) $customer->getGlobal(),
            ],
            $this->customerRepository->findAll(),
        ))];
    }
}
