<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use OTPHP\TOTP;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_string;
use function json_encode;

/**
 * Covers the admin break-glass reset (ADR-018 D2): POST /user/reset-2fa clears a
 * target user's TOTP secret AND backup codes. ROLE_ADMIN only; a self-reset is
 * refused (that path must go through the self-service, re-auth-gated flow); the
 * clear is unconditional and idempotent.
 *
 * Requests are sent as JSON (CONTENT_TYPE application/json) to match the SPA's
 * postJson + the endpoint's #[MapRequestPayload] binding.
 *
 * @internal
 *
 * @coversNothing
 */
final class ResetUserTwoFactorTest extends AbstractWebTestCase
{
    private const int ADMIN_ID = 1;   // unittest (ROLE_ADMIN)
    private const int TARGET_ID = 2;  // developer

    public function testAdminResetClearsAnotherUsersTwoFactorAndBackupCodes(): void
    {
        // Enrol the target user (developer, id 2) in their own session.
        $this->logInSession('developer');
        $this->enrolCurrentUser();
        self::assertNotNull($this->storedTotpSecret(self::TARGET_ID), 'precondition: the target is enrolled');
        self::assertNotNull($this->storedBackupCodes(self::TARGET_ID), 'precondition: the target has backup codes');

        // Switch to the admin and reset the target.
        $this->logInSession('unittest');
        $this->postReset(self::TARGET_ID);

        $this->assertStatusCode(200);
        self::assertTrue($this->jsonBody()['success'] ?? false);
        self::assertNull($this->storedTotpSecret(self::TARGET_ID), 'the secret is cleared');
        self::assertNull($this->storedBackupCodes(self::TARGET_ID), 'the backup codes are cleared too');
    }

    public function testAdminCannotResetTheirOwnTwoFactor(): void
    {
        $this->logInSession('unittest');
        $this->enrolCurrentUser();
        self::assertNotNull($this->storedTotpSecret(self::ADMIN_ID), 'precondition: the admin is enrolled');

        $this->postReset(self::ADMIN_ID);

        $this->assertStatusCode(400);
        self::assertNotNull($this->storedTotpSecret(self::ADMIN_ID), 'a self-reset must not clear the secret');
    }

    public function testResetIsIdempotentForAUserWithoutTwoFactor(): void
    {
        $this->logInSession('unittest');
        self::assertNull($this->storedTotpSecret(self::TARGET_ID), 'precondition: no 2FA enrolled');

        $this->postReset(self::TARGET_ID);

        $this->assertStatusCode(200);
        self::assertTrue($this->jsonBody()['success'] ?? false);
    }

    public function testResetUnknownUserReturns404(): void
    {
        $this->logInSession('unittest');

        $this->postReset(999999);

        $this->assertStatusCode(404);
    }

    public function testResetRequiresAdmin(): void
    {
        // A plain developer must not be able to strip another account's 2FA.
        $this->logInSession('developer');

        $this->postReset(self::ADMIN_ID);

        $this->assertStatusCode(403);
    }

    /** POST /user/reset-2fa with a JSON body, as the SPA does. */
    private function postReset(int $id): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/user/reset-2fa',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string) json_encode(['id' => $id]),
        );
    }

    /** Run the real TOTP enrolment (start → confirm) for the logged-in user. */
    private function enrolCurrentUser(): void
    {
        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/start', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $secret = $this->jsonBody()['secret'] ?? null;
        if (!is_string($secret) || '' === $secret) {
            self::fail('start endpoint did not return a secret');
        }

        $code = TOTP::createFromSecret($secret)->now();
        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/confirm', ['code' => $code], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
    }

    /**
     * @return array<mixed, mixed>
     */
    private function jsonBody(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);

        return $data;
    }

    private function storedTotpSecret(int $userId): ?string
    {
        return $this->storedColumn('totp_secret', $userId);
    }

    private function storedBackupCodes(int $userId): ?string
    {
        return $this->storedColumn('backup_codes', $userId);
    }

    private function storedColumn(string $column, int $userId): ?string
    {
        $connection = $this->connection;
        self::assertNotNull($connection);
        $value = $connection->fetchOne('SELECT ' . $column . ' FROM users WHERE id = ?', [$userId]);

        return is_string($value) ? $value : null;
    }
}
