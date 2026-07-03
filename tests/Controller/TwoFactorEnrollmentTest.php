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

/**
 * Covers the TOTP enrolment endpoints (ADR-018 D2): start → confirm → disable,
 * plus the confirm-without-pending-secret guard.
 *
 * @internal
 *
 * @coversNothing
 */
final class TwoFactorEnrollmentTest extends AbstractWebTestCase
{
    public function testStartConfirmDisableRoundTrip(): void
    {
        $this->logInSession('unittest');

        // 1) start → a provisioning URI and the pending secret (session-held).
        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/start', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $start = $this->jsonBody();
        $uri = $start['provisioningUri'] ?? null;
        self::assertIsString($uri);
        self::assertStringStartsWith('otpauth://totp/', $uri);
        $secret = $start['secret'] ?? null;
        if (!is_string($secret) || '' === $secret) {
            self::fail('start endpoint did not return a secret');
        }

        // 2) confirm with a valid code derived from that secret (±1 period leeway
        //    absorbs a clock tick between generating and verifying).
        $code = TOTP::createFromSecret($secret)->now();
        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/confirm', ['code' => $code], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $confirm = $this->jsonBody();
        self::assertTrue($confirm['enabled'] ?? false);
        self::assertIsArray($confirm['backupCodes'] ?? null);
        self::assertCount(8, $confirm['backupCodes']);

        // The secret is now stored (encrypted, non-null) on the user.
        self::assertNotNull($this->storedTotpSecret(1));

        // 3a) disable without a re-auth code is rejected (ADR-018 D4) — 2FA stays on.
        $this->client->request(Request::METHOD_POST, '/settings/2fa/disable', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        self::assertTrue($this->jsonBody()['enabled'] ?? false);
        self::assertNotNull($this->storedTotpSecret(1));

        // 3b) disable with a fresh code → cleared.
        $disableCode = TOTP::createFromSecret($secret)->now();
        $this->client->request(Request::METHOD_POST, '/settings/2fa/disable', ['code' => $disableCode], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        self::assertFalse($this->jsonBody()['enabled'] ?? true);
        self::assertNull($this->storedTotpSecret(1));
    }

    public function testConfirmWithoutStartIsRejected(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/confirm', ['code' => '123456'], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(400);
    }

    public function testInvalidCodeLeavesTwoFactorOff(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/start', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/confirm', ['code' => '000000'], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        self::assertNull($this->storedTotpSecret(1));
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
        $connection = $this->connection;
        self::assertNotNull($connection);
        $value = $connection->fetchOne('SELECT totp_secret FROM users WHERE id = ?', [$userId]);

        return is_string($value) ? $value : null;
    }
}
