<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\ProjectOnboardDto;
use App\Mcp\AdminEntityResolver;
use App\Mcp\ScopeGuard;
use App\Service\AdminOnboardingService;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

use function trim;

/**
 * MCP admin tool: onboard a project (ADR-022 Phase 3).
 */
final readonly class OnboardProjectTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private AdminEntityResolver $adminEntityResolver,
        private AdminOnboardingService $adminOnboardingService,
    ) {
    }

    /**
     * Create (onboard) a new, active project for a customer. Requires an
     * administrator account and the projects:write scope.
     *
     * @throws ToolCallException on an unknown customer or ticket system, or a validation failure
     *
     * @return array<string, mixed> the created project
     */
    #[McpTool(name: 'onboard_project', description: 'Onboard a new project for a customer (admin only).')]
    public function onboardProject(
        #[Schema(description: 'Project name (at least 3 characters).')]
        string $name,
        #[Schema(description: 'Customer name or numeric id the project belongs to.')]
        string $customer,
        #[Schema(description: 'Ticket prefix (capital letters, e.g. "ABC"). Empty if none.')]
        string $ticketPrefix = '',
        #[Schema(description: 'Whether the project is bookable by every team.')]
        bool $global = false,
        #[Schema(description: 'Ticket system name or numeric id that worklogs are booked into (list_ticketsystems). Empty leaves it unset, and entries on this project then sync nowhere.')]
        string $ticketSystem = '',
    ): array {
        $this->scopeGuard->requireAdminScope('projects:write');

        $customerEntity = $this->adminEntityResolver->customer($customer);
        $ticketSystemId = '' !== trim($ticketSystem)
            ? (int) $this->adminEntityResolver->ticketSystem($ticketSystem)->getId()
            : null;

        try {
            return $this->adminOnboardingService->onboardProject(new ProjectOnboardDto(
                name: $name,
                customer_id: (int) $customerEntity->getId(),
                jira_id: $ticketPrefix,
                global: $global,
                ticket_system_id: $ticketSystemId,
            ))->jsonSerialize();
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ToolCallException($invalidArgumentException->getMessage(), $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }
    }
}
