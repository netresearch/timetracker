<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\User;
use OTPHP\TOTP;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\LocalPasswordTestTrait;
use Tests\Traits\SpaLoginTestTrait;

use function password_hash;

use const PASSWORD_DEFAULT;

/**
 * Covers the enforced login challenge (ADR-018 D2, increment 3): a TOTP-enrolled
 * user's password login yields a half-authenticated session that must pass
 * /2fa_check, while non-enrolled users log in exactly as before.
 *
 * Runs against the firewall's two_factor block (mirrored in the test env
 * security config) — the whole flow stays in ONE kernel/session-storage; a fresh
 * login is simulated by clearing the client's cookie jar, never by rebooting the
 * kernel (that would break the per-test DB transaction).
 *
 * @internal
 *
 * @coversNothing
 */
final class TwoFactorLoginFlowTest extends AbstractWebTestCase
{
    use LocalPasswordTestTrait;
    use SpaLoginTestTrait;

    private const string PROBE_PATH = '/getUsers';

    private const string CHECK_PATH = '/2fa_check';

    /** Throwaway fixture value for the seeded test user's local login. */
    private const string VALID_LOGIN = 'Str0ng-Horse-Battery-42';

    public function testEnrolledUserMustPassTotpChallenge(): void
    {
        $secret = $this->enrolInTotp()['secret'];

        // Fresh, unauthenticated session (same kernel — see class docblock).
        $this->client->getCookieJar()->clear();

        // 1) Password step: correct credentials, but the session is only
        //    half-authenticated. The XHR login gets the explicit JSON signal from
        //    LoginFormAuthenticator (the SPA branches on twoFactorRequired)…
        $this->postLogin(self::VALID_LOGIN);
        $this->assertStatusCode(401);
        $login = $this->getJsonResponse($this->client->getResponse());
        self::assertFalse($login['ok'] ?? true);
        self::assertTrue($login['twoFactorRequired'] ?? false, 'the SPA signal must name the challenge');

        // …and a protected probe is answered by the JSON required-handler.
        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        $this->assertStatusCode(401);
        $body = $this->getJsonResponse($this->client->getResponse());
        self::assertFalse($body['ok'] ?? true);
        self::assertTrue($body['twoFactorRequired'] ?? false);

        // 2) A wrong code is rejected by the JSON failure handler.
        $this->requestJson(Request::METHOD_POST, self::CHECK_PATH, ['_auth_code' => '000000']);
        $this->assertStatusCode(401);
        self::assertFalse($this->getJsonResponse($this->client->getResponse())['ok'] ?? true);

        // 3) The valid current code completes the login…
        $this->requestJson(Request::METHOD_POST, self::CHECK_PATH, ['_auth_code' => TOTP::createFromSecret($secret)->now()]);
        $this->assertStatusCode(200);
        $success = $this->getJsonResponse($this->client->getResponse());
        self::assertTrue($success['ok'] ?? false);
        self::assertSame('/', $success['redirect'] ?? null);

        // 4) …and the protected resource is reachable.
        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        $this->assertStatusCode(200);
    }

    public function testBackupCodeCompletesTheChallenge(): void
    {
        $backupCodes = $this->enrolInTotp()['backupCodes'];

        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN);

        $this->requestJson(Request::METHOD_POST, self::CHECK_PATH, ['_auth_code' => $backupCodes[0]]);
        $this->assertStatusCode(200);
        self::assertTrue($this->getJsonResponse($this->client->getResponse())['ok'] ?? false);

        // The code is single-use: a second login with the same code fails.
        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN);
        $this->requestJson(Request::METHOD_POST, self::CHECK_PATH, ['_auth_code' => $backupCodes[0]]);
        $this->assertStatusCode(401);
    }

    public function testNonEnrolledUserLogsInWithoutChallenge(): void
    {
        // No TOTP secret on the account — the two_factor block must stay inert
        // and the XHR login completes in one step, exactly as before.
        $this->setStoredPassword(1, password_hash(self::VALID_LOGIN, PASSWORD_DEFAULT));

        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN);
        $this->assertStatusCode(200);
        self::assertTrue($this->getJsonResponse($this->client->getResponse())['ok'] ?? false);

        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        $this->assertStatusCode(200);
    }

    /**
     * Give user 1 a local password and enrol TOTP via the settings endpoints
     * (setUp already logged the session in).
     *
     * @return array{secret: non-empty-string, backupCodes: list<string>}
     */
    private function enrolInTotp(): array
    {
        $this->setStoredPassword(1, password_hash(self::VALID_LOGIN, PASSWORD_DEFAULT));
        // Re-login: the session token from setUp carries the OLD password hash, and
        // the ContextListener's refresh would treat the change as a user change and
        // deauthenticate (hasUserChanged compares the stored password).
        $this->logInSession('unittest');

        return $this->confirmTotpThroughSettings();
    }
}
