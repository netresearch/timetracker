<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AbstractWebTestCase;

/**
 * End-to-end API-token auth (ADR-021 Phase 2): the stateless Bearer firewall, the
 * authenticator, and #[RequireScope] enforcement including fail-closed.
 *
 * @internal
 *
 * @coversNothing
 */
final class ApiTokenAuthTest extends AbstractWebTestCase
{
    /**
     * @param list<string> $scopes
     */
    private function mintToken(array $scopes, bool $revoke = false): string
    {
        $container = self::getContainer();
        // The test container exposes private services (framework.test: true); the
        // Symfony PHPStan extension models only the runtime container.
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $apiTokenService = $container->get(ApiTokenService::class);
        self::assertInstanceOf(ApiTokenService::class, $apiTokenService);

        $user = $container->get('doctrine')->getManager()->getRepository(User::class)->findOneBy(['username' => 'unittest']);
        self::assertInstanceOf(User::class, $user);

        [$token, $plaintext] = $apiTokenService->create($user, 'test', $scopes);
        self::assertInstanceOf(ApiToken::class, $token);
        if ($revoke) {
            $apiTokenService->revoke($token);
        }

        return $plaintext;
    }

    private function requestWithToken(string $method, string $path, string $bearer): Response
    {
        $this->client->request($method, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer, 'HTTP_ACCEPT' => 'application/json']);

        return $this->client->getResponse();
    }

    public function testValidTokenWithMatchingScopeIsAuthorized(): void
    {
        $status = $this->requestWithToken(Request::METHOD_GET, '/getAllProjects', $this->mintToken(['projects:read']))->getStatusCode();

        self::assertSame(200, $status);
    }

    public function testWildcardScopeGrantsAnyEndpoint(): void
    {
        $status = $this->requestWithToken(Request::METHOD_GET, '/getAllProjects', $this->mintToken(['*']))->getStatusCode();

        self::assertSame(200, $status);
    }

    public function testTokenMissingTheRequiredScopeIsForbidden(): void
    {
        // entries:read does not grant projects:read.
        $status = $this->requestWithToken(Request::METHOD_GET, '/getAllProjects', $this->mintToken(['entries:read']))->getStatusCode();

        self::assertSame(Response::HTTP_FORBIDDEN, $status);
    }

    public function testEndpointWithoutRequireScopeIsForbiddenForTokens(): void
    {
        // Fail-closed: /getTicketSystems declares no #[RequireScope], so even a
        // wildcard token cannot reach it.
        $status = $this->requestWithToken(Request::METHOD_GET, '/getTicketSystems', $this->mintToken(['*']))->getStatusCode();

        self::assertSame(Response::HTTP_FORBIDDEN, $status);
    }

    public function testInvalidTokenIsUnauthorized(): void
    {
        $status = $this->requestWithToken(Request::METHOD_GET, '/getAllProjects', 'tt_pat_deadbeef')->getStatusCode();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $status);
    }

    public function testRevokedTokenIsUnauthorized(): void
    {
        $status = $this->requestWithToken(Request::METHOD_GET, '/getAllProjects', $this->mintToken(['*'], revoke: true))->getStatusCode();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $status);
    }
}
