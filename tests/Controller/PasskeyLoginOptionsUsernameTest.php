<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_array;
use function is_string;
use function json_encode;

/**
 * The anonymous passkey login options endpoint serves the usernameless flow
 * only (ADR-018 D3; the SPA always posts {}). A username in the request must
 * be refused (401 through the firewall's passkey failure handler) and must
 * not write to the account.
 *
 * @internal
 *
 * @coversNothing
 */
final class PasskeyLoginOptionsUsernameTest extends AbstractWebTestCase
{
    public function testAUsernameDoesNotMintAHandleOnTheAccount(): void
    {
        self::assertNull($this->storedUserHandle(1), 'precondition: user 1 has no passkey handle');

        $this->postOptions(['username' => 'unittest']);

        self::assertNull($this->storedUserHandle(1), 'an anonymous request must not write to the account');
    }

    public function testAUsernameIsRefusedWithoutCredentials(): void
    {
        $this->postOptions(['username' => 'unittest']);

        $this->assertStatusCode(401);
        [, $credentials] = $this->answerShape();
        self::assertNull($credentials, 'a refused request names no credentials');
    }

    public function testUsernamelessRequestStillGetsOptions(): void
    {
        $this->postOptions([]);

        $this->assertStatusCode(200);
        $body = $this->getJsonResponse($this->client->getResponse());
        self::assertArrayHasKey('challenge', $body);
        self::assertSame([], $body['allowCredentials'] ?? [], 'usernameless options name no credentials');
    }

    /**
     * @param array<string, string> $body
     */
    private function postOptions(array $body): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request(
            Request::METHOD_POST,
            '/login/options',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string) json_encode((object) $body),
        );
    }

    /**
     * Status code plus the credential list.
     *
     * @return array{int, mixed}
     */
    private function answerShape(): array
    {
        $response = $this->client->getResponse();
        $content = $response->getContent();
        $data = is_string($content) ? json_decode($content, true) : null;

        return [$response->getStatusCode(), is_array($data) ? ($data['allowCredentials'] ?? null) : null];
    }

    private function storedUserHandle(int $userId): ?string
    {
        self::assertNotNull($this->connection);
        $value = $this->connection->fetchOne('SELECT webauthn_user_handle FROM users WHERE id = ?', [$userId]);

        return is_string($value) ? $value : null;
    }
}
