<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Response\ProjectDto;
use App\Mcp\AdminEntityResolver;
use App\Mcp\ScopeGuard;
use App\Service\AdminOnboardingService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * MCP admin tool: activate or offboard (deactivate) a project (ADR-022
 * Phase 3).
 */
final readonly class SetProjectActiveTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private AdminEntityResolver $adminEntityResolver,
        private AdminOnboardingService $adminOnboardingService,
    ) {
    }

    /**
     * Activate or deactivate (offboard) a project. A deactivated project is no
     * longer bookable; its entries stay. Requires an administrator account and
     * the projects:write scope.
     *
     * @throws ToolCallException on an unknown project
     *
     * @return array<string, mixed> the updated project
     */
    #[McpTool(name: 'set_project_active', description: 'Activate or deactivate (offboard) a project (admin only).')]
    public function setProjectActive(
        #[Schema(description: 'Project name or numeric id.')]
        string $project,
        #[Schema(description: 'true to activate, false to offboard.')]
        bool $active,
    ): array {
        $this->scopeGuard->requireAdminScope('projects:write');

        $entity = $this->adminEntityResolver->project($project);
        $dto = $this->adminOnboardingService->setProjectActive((int) $entity->getId(), $active);
        if (!$dto instanceof ProjectDto) {
            throw new ToolCallException('The project could not be updated.');
        }

        return $dto->jsonSerialize();
    }
}
