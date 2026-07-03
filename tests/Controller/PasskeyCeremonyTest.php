<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_string;

/**
 * Covers that the WebAuthn ceremonies are wired and start correctly (ADR-018 D3):
 * the registration and login option endpoints return valid PublicKey*Options JSON
 * with a challenge. The credential crypto round-trip (attestation/assertion
 * verification) needs a browser virtual authenticator and is exercised by the e2e
 * suite, not here.
 *
 * @internal
 *
 * @coversNothing
 */
final class PasskeyCeremonyTest extends AbstractWebTestCase
{
    public function testRegistrationCeremonyReturnsCreationOptions(): void
    {
        // setUp logged the unittest user in; the ceremony resolves it via the
        // CurrentUserEntityGuesser and mints its webauthn_user_handle on first use.
        $this->client->request(
            Request::METHOD_POST,
            '/settings/security/passkeys/options',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        $this->assertStatusCode(200);
        $body = $this->getJsonResponse($this->client->getResponse());
        self::assertArrayHasKey('challenge', $body, 'creation options must carry a challenge');
        self::assertArrayHasKey('rp', $body);
        self::assertArrayHasKey('user', $body);

        // The handle is now assigned, so a later ceremony reuses the same identity.
        self::assertNotNull($this->storedUserHandle(1));
    }

    public function testLoginCeremonyReturnsRequestOptions(): void
    {
        // Discoverable login is public — no session needed for the options step.
        $this->client->getCookieJar()->clear();

        $this->client->request(
            Request::METHOD_POST,
            '/login/options',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        $this->assertStatusCode(200);
        $body = $this->getJsonResponse($this->client->getResponse());
        self::assertArrayHasKey('challenge', $body, 'request options must carry a challenge');
    }

    private function storedUserHandle(int $userId): ?string
    {
        self::assertNotNull($this->serviceContainer);
        $manager = $this->serviceContainer->get('doctrine')->getManager();
        $user = $manager->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        $handle = $user->getWebauthnUserHandle();

        return is_string($handle) ? $handle : null;
    }
}
