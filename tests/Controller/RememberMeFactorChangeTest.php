<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use OTPHP\TOTP;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Tests\AbstractWebTestCase;
use Tests\Traits\LocalPasswordTestTrait;
use Tests\Traits\SpaLoginTestTrait;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

use function json_encode;
use function password_hash;

use const JSON_THROW_ON_ERROR;
use const PASSWORD_DEFAULT;

/**
 * A remember-me cookie is signed over `password` and `twoFactorVersion`
 * (security.yaml), so any change of the user's second factors invalidates the
 * cookies issued before it (ADR-018 D4). Without that, a cookie from a
 * password-only login would keep authenticating after the user enrols a passkey
 * or TOTP, and would slide forward on every use. Each case below changes the
 * factors through the code path production uses, then presents the old cookie
 * alone, and checks that a cookie issued after the change still works.
 *
 * @internal
 *
 * @coversNothing
 */
final class RememberMeFactorChangeTest extends AbstractWebTestCase
{
    use LocalPasswordTestTrait;
    use SpaLoginTestTrait;

    private const string COOKIE_NAME = 'REMEMBERME';

    private const string JSON_MIME = 'application/json';

    private const string PROBE_PATH = '/getUsers';

    /** Throwaway fixture value for the seeded test users' local login. */
    private const string VALID_LOGIN = 'Str0ng-Horse-Battery-42';

    private const string HANDLE = 'aaaaaaaa-bbbb-cccc-dddd-eeeeffff0002';

    public function testEnrollingTotpInvalidatesTheOldCookie(): void
    {
        $old = $this->loginWithRememberMe('unittest', 1);
        $secret = $this->confirmTotpThroughSettings()['secret'];

        $this->assertCookieRejected($old);

        // A login after the change, completed with the TOTP code, gets a working cookie.
        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN, 'unittest', true);
        $this->assertStatusCode(401);
        self::assertTrue($this->getJsonResponse($this->client->getResponse())['twoFactorRequired'] ?? false);
        $this->requestJson(Request::METHOD_POST, '/2fa_check', ['_auth_code' => TOTP::createFromSecret($secret)->now()]);
        $this->assertStatusCode(200);
        $this->assertCookieAccepted($this->issuedCookie());
    }

    public function testAddingAPasskeyInvalidatesTheOldCookie(): void
    {
        $this->giveWebauthnHandle();
        $old = $this->loginWithRememberMe('unittest', 1);

        // The registration ceremony stores a new credential through this method
        // (a real WebAuthn ceremony cannot run headless).
        $this->credentials()->saveCredentialRecord($this->newCredentialRecord());

        $this->assertCookieRejected($old);
        $this->assertCookieAccepted($this->loginWithRememberMe('unittest', 1));
    }

    public function testRemovingAPasskeyInvalidatesTheOldCookie(): void
    {
        $this->giveWebauthnHandle();
        $this->credentials()->saveCredentialRecord($this->newCredentialRecord());
        $old = $this->loginWithRememberMe('unittest', 1);

        $credentials = $this->credentials()->findByUserHandle(self::HANDLE);
        self::assertCount(1, $credentials);
        $this->requestJson(Request::METHOD_POST, '/settings/security/passkeys/delete', ['id' => (string) $credentials[0]->getId()]);
        $this->assertStatusCode(200);

        $this->assertCookieRejected($old);
        $this->assertCookieAccepted($this->loginWithRememberMe('unittest', 1));
    }

    public function testAdminResetInvalidatesTheOldCookie(): void
    {
        $old = $this->loginWithRememberMe('developer', 2);

        $this->client->getCookieJar()->clear();
        $this->logInSession('unittest');
        $this->client->request(
            Request::METHOD_POST,
            '/user/reset-2fa',
            [],
            [],
            ['CONTENT_TYPE' => self::JSON_MIME, 'HTTP_ACCEPT' => self::JSON_MIME],
            json_encode(['id' => 2], JSON_THROW_ON_ERROR),
        );
        $this->assertStatusCode(200);

        $this->assertCookieRejected($old);
        $this->assertCookieAccepted($this->loginWithRememberMe('developer', 2));
    }

    public function testThePasskeyLoginCounterUpdateKeepsTheCookie(): void
    {
        // Control: every passkey login saves the credential again to store its
        // counter. That is not a factor change and must not void the cookie the
        // same login issues.
        $this->giveWebauthnHandle();
        $this->credentials()->saveCredentialRecord($this->newCredentialRecord());
        $cookie = $this->loginWithRememberMe('unittest', 1);

        $credentials = $this->credentials()->findByUserHandle(self::HANDLE);
        self::assertCount(1, $credentials);
        $credential = $credentials[0];
        self::assertInstanceOf(WebauthnCredential::class, $credential);
        ++$credential->counter;
        $this->credentials()->saveCredentialRecord($credential);

        $this->assertCookieAccepted($cookie);
    }

    private function loginWithRememberMe(string $username, int $userId): Cookie
    {
        $this->setStoredPassword($userId, password_hash(self::VALID_LOGIN, PASSWORD_DEFAULT));
        $this->client->getCookieJar()->clear();
        $this->postLogin(self::VALID_LOGIN, $username, true);
        $this->assertStatusCode(200);

        return $this->issuedCookie();
    }

    private function issuedCookie(): Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if (self::COOKIE_NAME === $cookie->getName() && !$cookie->isCleared()) {
                return $cookie;
            }
        }

        self::fail('the response must issue a live REMEMBERME cookie');
    }

    /** Present the cookie alone, as after a browser restart: the probe must be refused. */
    private function assertCookieRejected(Cookie $cookie): void
    {
        $this->restartBrowserWith($cookie);
        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode(), 'a cookie from before the factor change must not authenticate');
    }

    /** Present the cookie alone, as after a browser restart: the probe must be served. */
    private function assertCookieAccepted(Cookie $cookie): void
    {
        $this->restartBrowserWith($cookie);
        $this->requestJson(Request::METHOD_GET, self::PROBE_PATH);
        $this->assertStatusCode(200);
    }

    private function restartBrowserWith(Cookie $cookie): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new BrowserKitCookie(
            self::COOKIE_NAME,
            (string) $cookie->getValue(),
            (string) $cookie->getExpiresTime(),
            $cookie->getPath(),
        ));
    }

    private function giveWebauthnHandle(): void
    {
        self::assertNotNull($this->serviceContainer);
        $manager = $this->serviceContainer->get('doctrine')->getManager();
        $user = $manager->getRepository(User::class)->find(1);
        self::assertInstanceOf(User::class, $user);
        $user->setWebauthnUserHandle(self::HANDLE);
        $manager->flush();
    }

    private function credentials(): WebauthnCredentialRepository
    {
        self::assertNotNull($this->serviceContainer);
        $repository = $this->serviceContainer->get(WebauthnCredentialRepository::class);
        self::assertInstanceOf(WebauthnCredentialRepository::class, $repository);

        return $repository;
    }

    /** The base record the attestation validator hands to saveCredentialRecord on registration. */
    private function newCredentialRecord(): CredentialRecord
    {
        return new CredentialRecord(
            'remember-me-factor-change',
            'public-key',
            [],
            'none',
            new EmptyTrustPath(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'test-key',
            self::HANDLE,
            0,
        );
    }
}
