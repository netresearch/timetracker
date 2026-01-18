<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Tests\AbstractWebTestCase;

use function assert;

/**
 * Regression tests for remember_me authentication edge cases.
 *
 * These tests verify that users authenticated via remember_me (but not fully
 * authenticated) are properly redirected to logout instead of seeing a 403
 * error or getting stuck in a redirect loop.
 *
 * @internal
 *
 * @covers \App\EventSubscriber\AccessDeniedSubscriber
 */
final class RememberMeRedirectTest extends AbstractWebTestCase
{
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
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        $em = $this->serviceContainer->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Don't call logInSession() - we control auth per test
    }

    /**
     * Test that a remember_me authenticated user accessing a route requiring
     * IS_AUTHENTICATED_FULLY gets redirected to /logout (not 403).
     *
     * This is a regression test for the bug where remember_me users would see
     * "You are not allowed to perform this action" instead of being redirected
     * to re-authenticate.
     */
    public function testRememberMeUserRedirectsToLogoutNotForbidden(): void
    {
        // Get a real user from the database
        $user = $this->entityManager->getRepository(User::class)->findOneBy([]);
        if (!$user instanceof User) {
            self::markTestSkipped('No users in database to test with');
        }

        // Create a RememberMeToken (not fully authenticated)
        // Symfony 8: RememberMeToken only takes 2 parameters - $user and $firewallName
        $token = new RememberMeToken($user, 'main');

        // Set the token in the security context via session
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface $session */
        $session = $this->serviceContainer->get('session.factory')->createSession();
        $session->set('_security_main', serialize($token));
        $session->save();

        // Set the session cookie on the client
        $this->client->getCookieJar()->set(new Cookie(
            $session->getName(),
            $session->getId(),
            (string) (time() + 3600),
            '/',
            '',
            false,
            true,
        ));

        // Access a route that requires IS_AUTHENTICATED_FULLY
        $this->client->request('GET', '/');

        // Should redirect to /logout, NOT show 403
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);

        // Should redirect to /logout (Case 2 in AccessDeniedSubscriber)
        self::assertStringContainsString('/logout', $location,
            'Remember_me user should be redirected to /logout, not shown 403');
    }

    /**
     * Test that /logout is accessible (PUBLIC_ACCESS) and redirects to /login.
     *
     * This is a regression test for the bug where /logout required
     * IS_AUTHENTICATED_FULLY, causing an infinite /logout -> /logout loop.
     */
    public function testLogoutIsPublicAndRedirectsToLogin(): void
    {
        // Access /logout without any authentication
        $this->client->request('GET', '/logout');

        // Should redirect to /login, not 403 or loop
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString('/login', $location,
            '/logout should redirect to /login');
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
        self::assertStringContainsString('/login', $location,
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
        // Access /logout directly (simulating redirect from protected route)
        $this->client->request('GET', '/logout');

        // Follow redirect to /login
        $this->client->followRedirect();

        // Should be at login page with 200 OK
        self::assertResponseIsSuccessful();
    }
}
