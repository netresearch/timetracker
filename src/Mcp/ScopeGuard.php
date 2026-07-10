<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\User;
use App\Security\ApiToken\ApiAccessToken;
use App\ValueObject\ApiScope;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

use function in_array;
use function sprintf;

/**
 * Per-tool scope enforcement for the MCP server (ADR-021 Phase 5).
 *
 * MCP tools are invoked by the SDK, not routed through Symfony's controller
 * layer, so the #[RequireScope] voter does not run for them. This guard mirrors
 * RequireScopeSubscriber: it accepts only Bearer-PAT requests (ApiAccessToken),
 * checks the token grants the tool's scope (scopes narrow, never expand the
 * user's access), and returns the authenticated user for the handler to act as.
 */
final readonly class ScopeGuard
{
    public function __construct(
        private Security $security,
        private RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * @throws ToolCallException if the request is not PAT-authenticated or the
     *                           token does not grant $scope
     */
    public function requireScope(string $scope): User
    {
        $token = $this->security->getToken();
        if (!$token instanceof ApiAccessToken) {
            throw new ToolCallException('This tool requires a personal access token (Authorization: Bearer tt_pat_…).');
        }

        if (!ApiScope::grants($token->getScopes(), $scope)) {
            throw new ToolCallException(sprintf('The access token is missing the required scope "%s".', $scope));
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            throw new ToolCallException('No authenticated user for this token.');
        }

        return $user;
    }

    /**
     * Both gates for admin tools (ADR-022 Phase 3): the token must grant the
     * scope AND the owning user must hold ROLE_ADMIN — mirroring the
     * #[IsGranted('ROLE_ADMIN')] + #[RequireScope] pair on the v2 endpoints.
     * A scope can narrow but never expand what the user may do.
     *
     * @throws ToolCallException if not PAT-authenticated, the scope is missing,
     *                           or the user is not an admin
     */
    public function requireAdminScope(string $scope): User
    {
        $user = $this->requireScope($scope);

        if (!$this->isAdmin()) {
            throw new ToolCallException('This tool requires an administrator account.');
        }

        return $user;
    }

    /**
     * Whether the current token's user holds ROLE_ADMIN — the boolean twin of
     * requireAdminScope() for tools whose behaviour narrows (rather than
     * fails) for non-admins. Votes on the TOKEN's roles expanded through the
     * role hierarchy (e.g. ROLE_SUPER_ADMIN => ROLE_ADMIN) — the same input
     * and semantics as the #[IsGranted('ROLE_ADMIN')] check on the v2
     * endpoints.
     */
    public function isAdmin(): bool
    {
        $token = $this->security->getToken();
        $roles = $token instanceof ApiAccessToken ? $token->getRoleNames() : [];

        return in_array('ROLE_ADMIN', $this->roleHierarchy->getReachableRoleNames($roles), true);
    }
}
