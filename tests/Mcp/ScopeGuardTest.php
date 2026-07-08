<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp;

use App\Entity\User;
use App\Mcp\ScopeGuard;
use App\Security\ApiToken\ApiAccessToken;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * Unit tests for the MCP per-tool scope gate (ADR-021 Phase 5).
 */
final class ScopeGuardTest extends TestCase
{
    public function testRejectsWhenNoToken(): void
    {
        $guard = $this->guardWithToken(null);

        $this->expectException(ToolCallException::class);
        $guard->requireScope('entries:read');
    }

    public function testRejectsSessionTokenBecauseScopesGateTokenAuthOnly(): void
    {
        // A non-ApiAccessToken (e.g. a session/cookie login) must not reach MCP
        // tools: the scope model only governs token auth.
        $guard = $this->guardWithToken(self::createStub(TokenInterface::class));

        $this->expectException(ToolCallException::class);
        $guard->requireScope('entries:read');
    }

    public function testRejectsWhenTokenLacksScope(): void
    {
        $token = new ApiAccessToken(new User(), ['ROLE_USER'], ['reporting:read']);
        $guard = $this->guardWithToken($token);

        $this->expectException(ToolCallException::class);
        $guard->requireScope('entries:write');
    }

    public function testReturnsUserWhenScopeGranted(): void
    {
        $user = new User();
        $token = new ApiAccessToken($user, ['ROLE_USER'], ['entries:write']);
        $guard = $this->guardWithToken($token);

        self::assertSame($user, $guard->requireScope('entries:write'));
    }

    public function testWildcardScopeGrantsAnything(): void
    {
        $user = new User();
        $token = new ApiAccessToken($user, ['ROLE_USER'], ['*']);
        $guard = $this->guardWithToken($token);

        self::assertSame($user, $guard->requireScope('entries:write'));
    }

    public function testRequireAdminScopeHonorsRoleHierarchy(): void
    {
        // ROLE_SUPER_ADMIN reaches ROLE_ADMIN only through the hierarchy — the
        // guard must expand it exactly like #[IsGranted('ROLE_ADMIN')] does.
        $user = new User();
        $token = new ApiAccessToken($user, ['ROLE_SUPER_ADMIN'], ['projects:write']);

        self::assertSame($user, $this->guardWithToken($token)->requireAdminScope('projects:write'));
    }

    public function testRequireAdminScopeRejectsNonAdmins(): void
    {
        $token = new ApiAccessToken(new User(), ['ROLE_USER'], ['projects:write']);

        $this->expectException(ToolCallException::class);
        $this->guardWithToken($token)->requireAdminScope('projects:write');
    }

    private function guardWithToken(?TokenInterface $token): ScopeGuard
    {
        $security = self::createStub(Security::class);
        $security->method('getToken')->willReturn($token);

        return new ScopeGuard($security, new RoleHierarchy(['ROLE_SUPER_ADMIN' => ['ROLE_ADMIN']]));
    }
}
