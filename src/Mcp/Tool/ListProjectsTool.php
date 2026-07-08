<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ScopeGuard;
use App\Repository\ProjectRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * MCP tool: list the (bookable) projects, for picking one to log time against
 * (ADR-021 Phase 5).
 */
final readonly class ListProjectsTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private ProjectRepository $projectRepository,
    ) {
    }

    /**
     * List bookable projects (active or global), optionally filtered to one
     * customer. Returns id, name, customer, and the ticket prefixes the project
     * accepts. Use a project's name or id with `log_time`.
     *
     * The list is wrapped in an object — MCP structuredContent must be a JSON
     * object at the top level, never a bare array (#573, ADR-022 §4).
     *
     * @return array{projects: list<array{id: int|null, name: string, customer: string|null, customer_id: int|null, ticket_prefixes: string|null}>}
     */
    #[McpTool(name: 'list_projects', description: 'List bookable projects (active or global) to log time against.')]
    public function listProjects(
        #[Schema(description: 'Only projects for this customer id; omit for all customers.')]
        ?int $customerId = null,
    ): array {
        $this->scopeGuard->requireScope('projects:read');

        $projects = null !== $customerId && $customerId > 0
            ? $this->projectRepository->findByCustomer($customerId)
            : $this->projectRepository->findAll();

        $result = [];
        foreach ($projects as $project) {
            // Only the bookable set — an agent cannot usefully log against an
            // inactive, non-global project.
            if (!$project->getActive() && !$project->getGlobal()) {
                continue;
            }

            $customer = $project->getCustomer();
            $result[] = [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'customer' => $customer?->getName(),
                'customer_id' => $customer?->getId(),
                'ticket_prefixes' => $project->getJiraId(),
            ];
        }

        return ['projects' => $result];
    }
}
