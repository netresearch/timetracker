<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\ProjectUpdateDto;
use App\Mcp\AdminEntityResolver;
use App\Mcp\ScopeGuard;
use App\Service\AdminOnboardingService;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

use function trim;

/**
 * MCP admin tool: change an existing project (#688).
 *
 * `onboard_project` can only create. A project onboarded without a ticket
 * system books no worklogs, and until this tool existed the only repair was the
 * admin UI — which an agent cannot reach.
 */
final readonly class UpdateProjectTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private AdminEntityResolver $adminEntityResolver,
        private AdminOnboardingService $adminOnboardingService,
    ) {
    }

    /**
     * Change an existing project. Every field except the project itself is
     * optional; an omitted field is left as it is. Requires an administrator
     * account and the projects:write scope.
     *
     * @throws ToolCallException on an unknown project, customer or ticket system, or a validation failure
     *
     * @return array<string, mixed> the project as it now stands
     */
    #[McpTool(name: 'update_project', description: 'Change an existing project — name, customer, ticket prefix, ticket system, active/global (admin only).')]
    public function updateProject(
        #[Schema(description: 'Project name or numeric id to change.')]
        string $project,
        #[Schema(description: 'New project name. Empty leaves it unchanged.')]
        string $name = '',
        #[Schema(description: 'New customer name or numeric id. Empty leaves it unchanged.')]
        string $customer = '',
        #[Schema(description: 'New ticket prefix (capital letters). Empty leaves it unchanged.')]
        string $ticketPrefix = '',
        #[Schema(description: 'Ticket system name or numeric id that worklogs are booked into (list_ticketsystems). Empty leaves it unchanged.')]
        string $ticketSystem = '',
        #[Schema(description: 'Whether the project is bookable at all. Omit to leave unchanged.')]
        ?bool $active = null,
        #[Schema(description: 'Whether the project is bookable by every team. Omit to leave unchanged.')]
        ?bool $global = null,
    ): array {
        $this->scopeGuard->requireAdminScope('projects:write');

        $projectEntity = $this->adminEntityResolver->project($project);
        $customerId = '' !== trim($customer)
            ? (int) $this->adminEntityResolver->customer($customer)->getId()
            : null;
        $ticketSystemId = '' !== trim($ticketSystem)
            ? (int) $this->adminEntityResolver->ticketSystem($ticketSystem)->getId()
            : null;

        try {
            return $this->adminOnboardingService->updateProject(new ProjectUpdateDto(
                id: (int) $projectEntity->getId(),
                name: '' !== trim($name) ? $name : null,
                customer_id: $customerId,
                jira_id: '' !== trim($ticketPrefix) ? $ticketPrefix : null,
                global: $global,
                active: $active,
                ticket_system_id: $ticketSystemId,
            ))->jsonSerialize();
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ToolCallException($invalidArgumentException->getMessage(), $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }
    }
}
