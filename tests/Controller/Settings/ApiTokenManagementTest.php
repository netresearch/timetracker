<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Settings;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenService;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AbstractWebTestCase;

/**
 * The Settings API-token management endpoints (ADR-021 Phase 3): list, create
 * (plaintext shown once), and revoke — including the ownership guard on revoke and
 * the fail-closed rule that a Bearer token can never manage tokens.
 *
 * @internal
 *
 * @coversNothing
 */
final class ApiTokenManagementTest extends AbstractWebTestCase
{
    private const string JSON_MIME = 'application/json';

    private function entityManager(): EntityManagerInterface
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(Registry::class, $doctrine);
        $manager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function user(string $username): User
    {
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Persist a token fixture for $username directly (hash mirrors ApiTokenService)
     * and return [entity, plaintext].
     *
     * @param list<string> $scopes
     *
     * @return array{0: ApiToken, 1: string}
     */
    private function persistToken(string $username, array $scopes, ?DateTimeImmutable $expiresAt = null): array
    {
        $plaintext = ApiTokenService::PREFIX . bin2hex(random_bytes(32));
        $token = new ApiToken($this->user($username), 'fixture', hash('sha256', $plaintext), $scopes, new DateTimeImmutable(), $expiresAt);
        $entityManager = $this->entityManager();
        $entityManager->persist($token);
        $entityManager->flush();

        return [$token, $plaintext];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The `tokens` array from the list response, each row narrowed to an array.
     *
     * @return list<array<mixed>>
     */
    private function tokenRows(): array
    {
        $tokens = $this->jsonBody()['tokens'];
        self::assertIsArray($tokens);

        $rows = [];
        foreach ($tokens as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    public function testListReturnsOwnTokensWithTaxonomyAndNoSecret(): void
    {
        $this->logInSession('unittest');
        [$token] = $this->persistToken('unittest', ['entries:read']);

        $this->client->request(Request::METHOD_GET, '/settings/api-tokens', [], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $rows = $this->tokenRows();
        $body = $this->jsonBody();

        self::assertContains($token->getId(), array_column($rows, 'id'));
        // The taxonomy that drives the create-form picker.
        self::assertIsArray($body['resources']);
        self::assertContains('entries', $body['resources']);
        self::assertSame(['read', 'write'], $body['actions']);
        self::assertSame('*', $body['wildcard']);
        // The secret / its hash must never be serialized.
        foreach ($rows as $entry) {
            self::assertArrayNotHasKey('tokenHash', $entry);
            self::assertArrayNotHasKey('token', $entry);
        }
    }

    public function testListDoesNotLeakAnotherUsersTokens(): void
    {
        $this->logInSession('unittest');
        [$foreign] = $this->persistToken('developer', ['*']);

        $this->client->request(Request::METHOD_GET, '/settings/api-tokens', [], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertNotContains($foreign->getId(), array_column($this->tokenRows(), 'id'));
    }

    public function testCreateReturnsPlaintextOnceAndPersistsResolvableToken(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/settings/api-tokens/create', [
            'name' => 'CI pipeline',
            'scopes' => ['entries:read', 'projects:read'],
        ], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();

        self::assertIsString($body['token']);
        self::assertStringStartsWith(ApiTokenService::PREFIX, $body['token']);
        self::assertSame('CI pipeline', $body['name']);

        // The stored hash of the returned plaintext resolves the persisted token
        // (verified via the public repository, so this is env-independent).
        $persisted = $this->entityManager()
            ->getRepository(ApiToken::class)
            ->findOneBy(['tokenHash' => hash('sha256', $body['token'])]);
        self::assertInstanceOf(ApiToken::class, $persisted);
        self::assertSame(['entries:read', 'projects:read'], $persisted->getScopes());
        self::assertTrue($persisted->isActive(new DateTimeImmutable()));
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/settings/api-tokens/create', [
            'name' => '   ',
            'scopes' => ['entries:read'],
        ], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateRejectsUnknownScope(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/settings/api-tokens/create', [
            'name' => 'bad',
            'scopes' => ['entries:destroy'],
        ], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateRejectsEmptyScopeList(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/settings/api-tokens/create', [
            'name' => 'no scopes',
            'scopes' => [],
        ], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateNormalizesDateOnlyExpiryToEndOfDay(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/settings/api-tokens/create', [
            'name' => 'expiring',
            'scopes' => ['entries:read'],
            'expiresAt' => '2099-12-31',
        ], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $expiresAt = $this->jsonBody()['expiresAt'];
        self::assertIsString($expiresAt);
        // End-of-day, so the token stays valid through the whole date.
        self::assertStringStartsWith('2099-12-31T23:59:59', $expiresAt);
    }

    public function testRevokeMarksOwnTokenRevoked(): void
    {
        $this->logInSession('unittest');
        [$token] = $this->persistToken('unittest', ['*']);

        $this->client->request(Request::METHOD_POST, '/settings/api-tokens/revoke', ['id' => $token->getId()], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->jsonBody()['success']);

        // The controller shares this test's EntityManager (logInSession disables the
        // kernel reboot), so the flushed revocation is visible on the same instance.
        self::assertInstanceOf(DateTimeImmutable::class, $token->getRevokedAt());
        self::assertFalse($token->isActive(new DateTimeImmutable()));
    }

    public function testRevokeRejectsAnotherUsersToken(): void
    {
        $this->logInSession('unittest');
        [$foreign] = $this->persistToken('developer', ['*']);

        $this->client->request(Request::METHOD_POST, '/settings/api-tokens/revoke', ['id' => $foreign->getId()], [], ['HTTP_ACCEPT' => self::JSON_MIME]);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        // The ownership guard rejected before touching the foreign token.
        self::assertNull($foreign->getRevokedAt());
        self::assertTrue($foreign->isActive(new DateTimeImmutable()));
    }

    public function testBearerTokenCannotManageTokens(): void
    {
        // Fail-closed (ADR-021 §7): the management endpoints declare no
        // #[RequireScope], so even a wildcard Bearer token is denied — auth-state
        // management stays off the token firewall.
        [, $plaintext] = $this->persistToken('unittest', ['*']);

        $this->client->request(Request::METHOD_GET, '/settings/api-tokens', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext,
            'HTTP_ACCEPT' => self::JSON_MIME,
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }
}
