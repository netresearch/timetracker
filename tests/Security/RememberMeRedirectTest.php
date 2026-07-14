<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Tests\AbstractWebTestCase;

use function assert;

/**
 * Regression tests for remember_me authentication edge cases.
 *
 * These tests verify that users authenticated via remember_me are served on
 * day-to-day routes (#587 — the previous force-logout made "Stay logged in"
 * useless), that a stale REMEMBERME cookie is cleaned up with a redirect to
 * /login instead of a 403 or a redirect loop, and that logout CSRF is enforced.
 *
 * @internal
 *
 * @covers \App\EventSubscriber\AccessDeniedSubscriber
 */
final class RememberMeRedirectTest extends AbstractWebTestCase
{
    private const string MSG_NO_CONTAINER = 'Service container not initialized';

    private const string PATH_LOGIN = '/login';

    private const string PATH_LOGOUT = '/logout';

    private EntityManagerInterface $entityManager;

    /**
     * Skip auto-login from parent - we control authentication in each test.
     *
     * @phpstan-ignore phpunit.callParent (parent::setUp intentionally skipped to control auth)
     */
    protected function setUp(): void
    {
        // Call grandparent setUp to boot kernel (skip AbstractWebTestCase::setUp which auto-logs in)
        \Symfony\Bundle\FrameworkBundle\Test\WebTestCase::setUp();

        // Initialize HTTP client (from HttpClientTrait)
        $this->initializeHttpClient();

        // Initialize database (from DatabaseTestTrait)
        $this->initializeDatabase();

        // Initialize entity manager
        assert(null !== $this->serviceContainer, self::MSG_NO_CONTAINER);
        $em = $this->serviceContainer->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Don't call logInSession() - we control auth per test
    }

    /**
     * A remember_me-authenticated session must be SERVED on day-to-day routes
     * (#587). The catch-all access rule accepts IS_AUTHENTICATED_REMEMBERED —
     * the pre-#587 behavior (force-logout to /login on the first request after
     * a browser restart, which also caused the historic 403/redirect loop)
     * must not return.
     */
    public function testRememberMeUserIsServedNotLoggedOut(): void
    {
        $this->authenticateViaRememberMeToken();

        // A day-to-day data route (requires IS_AUTHENTICATED_REMEMBERED)
        $this->client->request('GET', '/getUsers');

        self::assertResponseIsSuccessful('Remembered user must be served, not logged out');

        // The remembered session must survive: no REMEMBERME cookie deletion.
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('REMEMBERME' === $cookie->getName()) {
                self::assertFalse($cookie->isCleared(), 'REMEMBERME cookie must not be deleted');
            }
        }
    }

    /**
     * A remembered session reaching an IS_AUTHENTICATED_FULLY endpoint (ADR-018
     * re-auth posture) is redirected to /login by the form_login entry point —
     * a step-up prompt, NOT a logout: the session cookie and REMEMBERME cookie
     * stay untouched, so day-to-day routes keep working afterwards.
     */
    public function testRememberMeUserIsRedirectedToStepUpOnSensitiveRoute(): void
    {
        $this->authenticateViaRememberMeToken();

        $this->client->request('GET', '/settings/api-tokens');

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString(self::PATH_LOGIN, $location,
            'Remembered user must be sent to /login to step up, not shown 403');

        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('REMEMBERME' === $cookie->getName()) {
                self::assertFalse($cookie->isCleared(), 'step-up redirect must not delete the REMEMBERME cookie');
            }
        }

        // The remembered session is still alive after the denied request.
        $this->client->request('GET', '/getUsers');
        self::assertResponseIsSuccessful('the remembered session must survive a step-up redirect');
    }

    /**
     * Store a RememberMeToken (authenticated, but not full-fledged) in a real
     * session and attach its cookie to the client — the state Symfony's
     * remember-me authenticator leaves behind after resurrecting a session
     * from the REMEMBERME cookie.
     */
    private function authenticateViaRememberMeToken(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(1);
        self::assertInstanceOf(User::class, $user);

        // Symfony 8: RememberMeToken only takes 2 parameters - $user and $firewallName
        $token = new RememberMeToken($user, 'main');

        assert(null !== $this->serviceContainer, self::MSG_NO_CONTAINER);
        /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface $session */
        $session = $this->serviceContainer->get('session.factory')->createSession();
        $session->set('_security_main', serialize($token));
        $session->save();

        $this->client->getCookieJar()->set(new Cookie(
            $session->getName(),
            $session->getId(),
            (string) (time() + 3600),
            '/',
            '',
            false,
            true,
        ));
    }

    /**
     * A bare GET /logout without CSRF token must be rejected: logout CSRF
     * blocks cross-site forced logout (and BrowserKit sends no same-origin
     * fetch metadata either).
     *
     * The firewall wraps the LogoutException into AccessDeniedHttpException
     * (403). This app runs with error_controller: null, so the kernel
     * rethrows for HTML requests and the global error handler renders the
     * 403 page in production — in tests the wrapped exception surfaces.
     */
    public function testLogoutWithoutCsrfTokenIsRejected(): void
    {
        try {
            $this->client->request('GET', self::PATH_LOGOUT);
            self::fail('Tokenless logout must be rejected');
        } catch (AccessDeniedHttpException $accessDeniedHttpException) {
            self::assertStringContainsString('Invalid CSRF token', $accessDeniedHttpException->getMessage());
        }
    }

    /**
     * Test that /logout is accessible (PUBLIC_ACCESS) with a valid token and
     * same-origin fetch metadata, and redirects to /login.
     *
     * This is a regression test for the bug where /logout required
     * IS_AUTHENTICATED_FULLY, causing an infinite /logout -> /logout loop.
     */
    public function testLogoutIsPublicAndRedirectsToLogin(): void
    {
        $this->client->request('GET', self::PATH_LOGOUT, [
            '_csrf_token' => $this->logoutCsrfToken(),
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        // Should redirect to /login, not 403 or loop
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString(self::PATH_LOGIN, $location,
            '/logout should redirect to /login');
    }

    private function logoutCsrfToken(): string
    {
        assert(null !== $this->serviceContainer, self::MSG_NO_CONTAINER);

        $csrfTokenManager = $this->serviceContainer->get('security.csrf.token_manager');
        assert($csrfTokenManager instanceof \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface);

        return $csrfTokenManager->getToken('logout')->getValue();
    }

    /**
     * Test that an unauthenticated user with a stale REMEMBERME cookie
     * gets redirected to /login (not 403) and the cookie is cleared.
     *
     * This simulates the scenario where a user has a REMEMBERME cookie
     * for a user that no longer exists in the database.
     */
    public function testStaleRememberMeCookieRedirectsToLogin(): void
    {
        // Set a fake REMEMBERME cookie (for non-existent user)
        $this->client->getCookieJar()->set(new Cookie(
            'REMEMBERME',
            base64_encode('App\\Entity\\User:nonexistent') . ':' . time() . ':fakehash',
            (string) (time() + 3600),
            '/',
            '',
            false,
            true,
        ));

        // Access a protected route
        $this->client->request('GET', '/');

        // Should redirect to /login
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString(self::PATH_LOGIN, $location,
            'Stale remember_me cookie should redirect to /login');

        // The REMEMBERME cookie should be cleared (deleted)
        $responseCookies = $this->client->getResponse()->headers->getCookies();
        $rememberMeCleared = false;
        foreach ($responseCookies as $cookie) {
            if ('REMEMBERME' === $cookie->getName() && $cookie->isCleared()) {
                $rememberMeCleared = true;
                break;
            }
        }
        self::assertTrue($rememberMeCleared, 'REMEMBERME cookie should be cleared');
    }

    /**
     * Test the complete flow: /logout -> /login -> 200 OK.
     *
     * This ensures there's no redirect loop and the user can eventually
     * reach the login page.
     */
    public function testLogoutToLoginCompleteFlow(): void
    {
        // Access /logout with a valid token + same-origin fetch metadata
        $this->client->request('GET', self::PATH_LOGOUT, [
            '_csrf_token' => $this->logoutCsrfToken(),
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        // Follow redirect to /login
        $this->client->followRedirect();

        // Should be at login page with 200 OK
        self::assertResponseIsSuccessful();
    }
}
