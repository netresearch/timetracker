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
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Registry;
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
     * Persist a token fixture directly (only the public `doctrine` service, so the
     * PHPStan symfony-container check is env-independent) and return its plaintext.
     * The stored hash mirrors ApiTokenService — a drift there makes the token
     * unresolvable and these tests fail, so the coupling is caught, not silent.
     *
     * @param list<string> $scopes
     */
    private function mintToken(array $scopes, bool $revoke = false): string
    {
        /** @var Registry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $entityManager = $doctrine->getManager();

        $user = $entityManager->getRepository(User::class)->findOneBy(['username' => 'unittest']);
        self::assertInstanceOf(User::class, $user);

        $plaintext = ApiTokenService::PREFIX . bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();
        $token = new ApiToken($user, 'test', hash('sha256', $plaintext), array_values($scopes), $now, null, null, $revoke ? $now : null);
        $entityManager->persist($token);
        $entityManager->flush();

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

    public function testBearerSchemeIsAcceptedCaseInsensitively(): void
    {
        // RFC 7235: the auth-scheme name is case-insensitive, so "bearer" is valid.
        $this->client->request(Request::METHOD_GET, '/getAllProjects', [], [], [
            'HTTP_AUTHORIZATION' => 'bearer ' . $this->mintToken(['projects:read']),
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
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
