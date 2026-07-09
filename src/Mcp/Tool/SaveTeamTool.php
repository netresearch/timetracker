<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Controller\Admin\SaveTeamAction;
use App\Dto\TeamSaveDto;
use App\Mcp\AdminEntityResolver;
use App\Mcp\DecodesActionResponse;
use App\Mcp\ScopeGuard;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;

/**
 * MCP admin tool: create or update a team (ADR-022 Phase 4). Both gates:
 * ROLE_ADMIN and teams:write. Deletion is intentionally not offered.
 */
final readonly class SaveTeamTool
{
    use DecodesActionResponse;

    public function __construct(
        private ScopeGuard $scopeGuard,
        private SaveTeamAction $saveTeamAction,
        private AdminEntityResolver $resolver,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * Create a team (omit `teamId`) or update an existing one (pass `teamId`).
     * The lead user is given by username or id. Requires an administrator
     * account and the teams:write scope.
     *
     * @throws ToolCallException on a validation failure or unknown lead user
     *
     * @return array<array-key, mixed> the saved team
     */
    #[McpTool(name: 'save_team', description: 'Create or update a team (admin only).')]
    public function saveTeam(
        #[Schema(description: 'Team name.')]
        string $name,
        #[Schema(description: 'Lead user: username or numeric id.')]
        string $leadUser,
        #[Schema(description: 'Existing team id to update; omit to create.', minimum: 1)]
        ?int $teamId = null,
    ): array {
        $this->scopeGuard->requireAdminScope('teams:write');

        $dto = new TeamSaveDto(
            id: $teamId ?? 0,
            name: $name,
            lead_user_id: (int) $this->resolver->user($leadUser)->getId(),
        );
        $this->assertValid($dto);

        $response = ($this->saveTeamAction)($dto);
        $body = $this->decodeBody($response);
        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException($this->errorMessageFromResponse($response, 'Failed to save the team.'));
        }

        return ['team' => $body];
    }

    private function assertValid(object $dto): void
    {
        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            throw new ToolCallException((string) $violations->get(0)->getMessage());
        }
    }
}
