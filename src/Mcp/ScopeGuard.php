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
    public function __construct(private Security $security)
    {
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
}
