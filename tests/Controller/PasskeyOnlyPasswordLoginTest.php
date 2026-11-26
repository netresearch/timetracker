<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use OTPHP\TOTP;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\LocalPasswordTestTrait;
use Tests\Traits\SpaLoginTestTrait;

use function getenv;
use function password_hash;
use function putenv;

use const PASSWORD_DEFAULT;

/**
 * Mandatory 2FA (REQUIRE_TWO_FACTOR) against the real firewall: a user whose only
 * second factor is a passkey must sign in with the passkey. A passkey is a login
 * of its own (ADR-018 D3), so it does not turn a password login into a
 * two-factor one; scheb challenges only TOTP users, so without a check the
 * password alone would open a full session for such a user.
 *
 * @internal
 *
 * @coversNothing
 */
final class PasskeyOnlyPasswordLoginTest extends AbstractWebTestCase
{
    use LocalPasswordTestTrait;
    use SpaLoginTestTrait;

    private const string PROBE_PATH = '/getUsers';

    /** Throwaway fixture value for the seeded test user's local login. */
    private const string VALID_LOGIN = 'Str0ng-Horse-Battery-42';

    private const string HANDLE = 'aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001';

    /** @var array{env: mixed, server: mixed, process: string|false}|null */
    private ?array $flagBackup = null;

    #[Override]
    protected function setUp(): void
    {
        $this->setRequireTwoFactor('true');
        parent::setUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->restoreRequireTwoFactor();
        parent::tearDown();
    }

    public function testPasskeyOnlyUserCannotSignInWithThePasswordAlone(): void
    {
        $this->givePasskey();
        $this->giveLocalPassword();

        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN);

        $this->assertStatusCode(401);
        $login = $this->getJsonResponse($this->client->getResponse());
        self::assertFalse($login['ok'] ?? true, 'the password alone must not complete the login');
        self::assertSame('Sign in with your passkey.', $login['error'] ?? null);
        self::assertTrue($login['passkeyRequired'] ?? false, 'the SPA signal must name the passkey');

        // No session was established: the protected probe is not reachable.
        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testPasskeyOnlyUserWithAWrongPasswordGetsTheGenericError(): void
    {
        $this->givePasskey();
        $this->giveLocalPassword();

        $this->client->getCookieJar()->clear();
        $this->postLogin('wrong-' . self::VALID_LOGIN);

        $this->assertStatusCode(401);
        $login = $this->getJsonResponse($this->client->getResponse());
        self::assertNotSame('Sign in with your passkey.', $login['error'] ?? null, 'a wrong password must not reveal the enrolled passkey');
        self::assertArrayNotHasKey('passkeyRequired', $login);
    }

    public function testUserWithoutASecondFactorIsConfinedToEnrolment(): void
    {
        // Proves the flag is on in this kernel: the password login completes, but
        // the gate confines the session to the enrolment surface.
        $this->giveLocalPassword();

        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN);
        $this->assertStatusCode(200);

        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        $this->assertStatusCode(403);
        self::assertSame('TwoFactorRequired', $this->getJsonResponse($this->client->getResponse())['error'] ?? null);
    }

    public function testUserWithTotpAndPasskeyIsChallengedForTheCode(): void
    {
        $this->givePasskey();
        $this->giveLocalPassword();
        $secret = $this->confirmTotpThroughSettings()['secret'];

        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN);
        $this->assertStatusCode(401);
        self::assertTrue($this->getJsonResponse($this->client->getResponse())['twoFactorRequired'] ?? false);

        $this->requestJson(Request::METHOD_POST, '/2fa_check', ['_auth_code' => TOTP::createFromSecret($secret)->now()]);
        $this->assertStatusCode(200);

        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        $this->assertStatusCode(200);
    }

    public function testWithoutMandatoryTwoFactorThePasswordLoginIsUnchanged(): void
    {
        $this->restoreRequireTwoFactor();
        $this->setRequireTwoFactor('false');
        // The flag is read when the kernel boots: start a fresh one with it off.
        self::ensureKernelShutdown();
        parent::setUp();

        $this->givePasskey();
        $this->giveLocalPassword();

        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN);
        $this->assertStatusCode(200);

        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        $this->assertStatusCode(200);
    }

    /** Seed one passkey for user 1 (a real WebAuthn ceremony can't run headless). */
    private function givePasskey(): void
    {
        self::assertNotNull($this->connection);
        $this->connection->executeStatement('UPDATE users SET webauthn_user_handle = ? WHERE id = 1', [self::HANDLE]);
        $this->connection->executeStatement(
            "INSERT INTO webauthn_credentials (public_key_credential_id, type, transports, attestation_type, trust_path, aaguid, credential_public_key, user_handle, counter, other_ui, backup_eligible, backup_status, uv_initialized)
             VALUES ('cGFzc2tleS1vbmx5', 'public-key', '[]', 'none', '{}', '00000000-0000-0000-0000-000000000000', 'dGVzdC1rZXk=', ?, 0, NULL, NULL, NULL, NULL)",
            [self::HANDLE],
        );
    }

    private function giveLocalPassword(): void
    {
        $this->setStoredPassword(1, password_hash(self::VALID_LOGIN, PASSWORD_DEFAULT));
        // The setUp session carries the old password hash; log in again so the
        // ContextListener refresh does not treat the change as a user change.
        $this->logInSession('unittest');
    }

    private function setRequireTwoFactor(string $value): void
    {
        $this->flagBackup ??= [
            'env' => $_ENV['REQUIRE_TWO_FACTOR'] ?? null,
            'server' => $_SERVER['REQUIRE_TWO_FACTOR'] ?? null,
            'process' => getenv('REQUIRE_TWO_FACTOR', true),
        ];
        $_ENV['REQUIRE_TWO_FACTOR'] = $value;
        $_SERVER['REQUIRE_TWO_FACTOR'] = $value;
        putenv('REQUIRE_TWO_FACTOR=' . $value);
    }

    private function restoreRequireTwoFactor(): void
    {
        if (null === $this->flagBackup) {
            return;
        }

        unset($_ENV['REQUIRE_TWO_FACTOR'], $_SERVER['REQUIRE_TWO_FACTOR']);
        if (null !== $this->flagBackup['env']) {
            $_ENV['REQUIRE_TWO_FACTOR'] = $this->flagBackup['env'];
        }
        if (null !== $this->flagBackup['server']) {
            $_SERVER['REQUIRE_TWO_FACTOR'] = $this->flagBackup['server'];
        }

        $process = $this->flagBackup['process'];
        putenv(false === $process ? 'REQUIRE_TWO_FACTOR' : 'REQUIRE_TWO_FACTOR=' . $process);
        $this->flagBackup = null;
    }
}
