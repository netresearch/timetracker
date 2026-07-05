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

/**
 * Unit tests for the MCP per-tool scope gate (ADR-021 Phase 5).
 */
final class ScopeGuardTest extends TestCase
{
    public function testRejectsWhenNoToken(): void
    {
        $guard = new ScopeGuard($this->securityWithToken(null));

        $this->expectException(ToolCallException::class);
        $guard->requireScope('entries:read');
    }

    public function testRejectsSessionTokenBecauseScopesGateTokenAuthOnly(): void
    {
        // A non-ApiAccessToken (e.g. a session/cookie login) must not reach MCP
        // tools: the scope model only governs token auth.
        $guard = new ScopeGuard($this->securityWithToken(self::createStub(TokenInterface::class)));

        $this->expectException(ToolCallException::class);
        $guard->requireScope('entries:read');
    }

    public function testRejectsWhenTokenLacksScope(): void
    {
        $token = new ApiAccessToken(new User(), ['ROLE_USER'], ['reporting:read']);
        $guard = new ScopeGuard($this->securityWithToken($token));

        $this->expectException(ToolCallException::class);
        $guard->requireScope('entries:write');
    }

    public function testReturnsUserWhenScopeGranted(): void
    {
        $user = new User();
        $token = new ApiAccessToken($user, ['ROLE_USER'], ['entries:write']);
        $guard = new ScopeGuard($this->securityWithToken($token));

        self::assertSame($user, $guard->requireScope('entries:write'));
    }

    public function testWildcardScopeGrantsAnything(): void
    {
        $user = new User();
        $token = new ApiAccessToken($user, ['ROLE_USER'], ['*']);
        $guard = new ScopeGuard($this->securityWithToken($token));

        self::assertSame($user, $guard->requireScope('entries:write'));
    }

    private function securityWithToken(?TokenInterface $token): Security
    {
        $security = self::createStub(Security::class);
        $security->method('getToken')->willReturn($token);

        return $security;
    }
}
