<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Security;

use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\LocalPasswordTestTrait;

use function password_hash;

use const PASSWORD_DEFAULT;

/**
 * End-to-end coverage for "Stay logged in" (#587): a password login with
 * `_remember_me` issues the 30-day REMEMBERME cookie, and that cookie alone —
 * the browser-session cookie is gone after a restart — re-authenticates the
 * next request instead of bouncing the user to the login form. Sensitive
 * endpoints (ADR-018 re-auth posture) still demand a full login, but reaching
 * them with a remembered session must redirect to /login WITHOUT destroying
 * the remembered session.
 *
 * @internal
 *
 * @coversNothing
 */
final class RememberMeFlowTest extends AbstractWebTestCase
{
    use LocalPasswordTestTrait;
    private const string COOKIE_NAME = 'REMEMBERME';

    private const string PROBE_PATH = '/getUsers';

    /** Throwaway fixture value for the seeded test user's local login. */
    private const string VALID_LOGIN = 'Str0ng-Horse-Battery-42';

    public function testLoginWithRememberMeIssuesThirtyDayCookie(): void
    {
        $cookie = $this->loginWithRememberMe();

        // lifetime: 2592000 (30 days) from security.yaml — allow the few
        // seconds between cookie creation and this assertion.
        $maxAge = $cookie->getExpiresTime() - time();
        self::assertGreaterThan(2592000 - 60, $maxAge, 'REMEMBERME must live ~30 days');
        self::assertLessThanOrEqual(2592000, $maxAge);
        self::assertTrue($cookie->isHttpOnly());
    }

    public function testLoginWithoutRememberMeIssuesNoCookie(): void
    {
        $this->prepareLocalPassword();
        $this->postLogin(withRememberMe: false);
        $this->assertStatusCode(200);

        // Symfony answers a parameter-less login with a CLEARING Set-Cookie
        // (REMEMBERME=deleted, Max-Age=0) — either that or no header at all is
        // acceptable; a live 30-day cookie is not.
        $cookie = $this->responseCookie(self::COOKIE_NAME);
        self::assertTrue(
            null === $cookie || $cookie->isCleared(),
            'No live REMEMBERME cookie may be issued when the checkbox is off',
        );
    }

    public function testRememberMeCookieAloneAuthenticatesAfterBrowserRestart(): void
    {
        $rememberMe = $this->loginWithRememberMe();

        $this->simulateBrowserRestart($rememberMe);

        // The very first request after the "restart" must be served, not bounced
        // to /login (the pre-#587 behavior force-logged the user out here).
        $this->client->request(Request::METHOD_GET, self::PROBE_PATH, [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertStatusCode(200);

        self::assertRememberMeCookieNotCleared($this->client->getResponse()->headers->getCookies());
    }

    public function testSensitiveEndpointRedirectsRememberedUserToLoginWithoutDestroyingCookie(): void
    {
        $rememberMe = $this->loginWithRememberMe();

        $this->simulateBrowserRestart($rememberMe);

        // /settings/* carries the ADR-018 re-auth posture: a remembered session
        // must re-enter credentials. The response is the form_login entry
        // point's redirect — NOT a logout, so the REMEMBERME cookie survives
        // and day-to-day routes keep working afterwards.
        $this->client->request(Request::METHOD_GET, '/settings/api-tokens', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString('/login', $location);

        self::assertRememberMeCookieNotCleared($this->client->getResponse()->headers->getCookies());

        // The remembered session is still intact: a normal route stays reachable.
        $this->client->request(Request::METHOD_GET, self::PROBE_PATH, [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->assertStatusCode(200);
    }

    public function testRememberedAdminCannotImpersonateWithoutFullLogin(): void
    {
        $rememberMe = $this->loginWithRememberMe();

        $this->simulateBrowserRestart($rememberMe);

        // switch_user (?simulateUserId=…) is an impersonation grant — a stolen
        // REMEMBERME cookie must never be enough. For the seeded user 1
        // (ROLE_ADMIN, no ROLE_ALLOWED_TO_SWITCH) the firewall's role check
        // denies the switch; for a remembered super-admin the
        // RequireFullAuthForImpersonationSubscriber does (unit-tested).
        // Either way the remembered user must land on the step-up redirect
        // below — never a completed switch, never a logout.
        $this->client->request(Request::METHOD_GET, self::PROBE_PATH . '?simulateUserId=2', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString('/login', $location);

        self::assertRememberMeCookieNotCleared($this->client->getResponse()->headers->getCookies());
    }

    /**
     * Give the seeded user a known local password, then perform the SPA's XHR
     * login with the remember-me checkbox ticked; returns the issued cookie.
     */
    private function loginWithRememberMe(): Cookie
    {
        $this->prepareLocalPassword();
        $this->postLogin(withRememberMe: true);
        $this->assertStatusCode(200);

        $cookie = $this->responseCookie(self::COOKIE_NAME);
        self::assertNotNull($cookie, 'login with _remember_me must issue the REMEMBERME cookie');

        return $cookie;
    }

    private function prepareLocalPassword(): void
    {
        $this->setStoredPassword(1, password_hash(self::VALID_LOGIN, PASSWORD_DEFAULT));
        // Fresh, unauthenticated "browser" — setUp()'s session belongs to the
        // pre-password-change token and would be deauthenticated anyway.
        $this->client->getCookieJar()->clear();
    }

    /**
     * POST the password step the way the SPA does (fetch/XHR): render the login
     * page first for the 'authenticate' CSRF token, then submit credentials.
     */
    private function postLogin(bool $withRememberMe): void
    {
        $this->client->request(Request::METHOD_GET, '/login');
        $html = $this->client->getResponse()->getContent();
        self::assertIsString($html);
        self::assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $matches), 'login page must render the CSRF token');

        $parameters = [
            '_username' => 'unittest',
            '_password' => self::VALID_LOGIN,
            '_csrf_token' => $matches[1],
        ];
        if ($withRememberMe) {
            $parameters['_remember_me'] = 'on';
        }

        $this->client->request(Request::METHOD_POST, '/login', $parameters, [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
    }

    /**
     * Drop every cookie (the browser-session cookie above all) and carry over
     * ONLY the REMEMBERME cookie — the state a browser restart leaves behind.
     */
    private function simulateBrowserRestart(Cookie $rememberMe): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new BrowserKitCookie(
            self::COOKIE_NAME,
            (string) $rememberMe->getValue(),
            (string) $rememberMe->getExpiresTime(),
            $rememberMe->getPath(),
        ));
    }

    private function responseCookie(string $name): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @param array<Cookie> $cookies
     */
    private static function assertRememberMeCookieNotCleared(array $cookies): void
    {
        foreach ($cookies as $cookie) {
            if (self::COOKIE_NAME === $cookie->getName()) {
                self::assertFalse(
                    $cookie->isCleared(),
                    'the response must not delete the REMEMBERME cookie',
                );
            }
        }
    }
}
