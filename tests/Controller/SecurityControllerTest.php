<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SecurityControllerTest extends AbstractWebTestCase
{
    /**
     * Override setUp to not automatically log in for this test class.
     */
    protected function setUp(): void
    {
        // Call grandparent setUp to skip the automatic login in AbstractWebTestCase
        \Symfony\Bundle\FrameworkBundle\Test\WebTestCase::setUp();

        // Initialize HTTP client (from HttpClientTrait)
        $this->initializeHttpClient();

        // Initialize database and transactions (from DatabaseTestTrait)
        $this->initializeDatabase();

        // DO NOT call logInSession() - we want to test unauthenticated access
    }

    public function testAccessToProtectedRouteReturnsForbidden(): void
    {
        // Session is already cleared in setUp, just verify we're not logged in
        $session = $this->client->getContainer()->get('session');
        assert($session instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface);
        $session->clear();
        $session->save();

        // Also clear the security token to ensure full logout in test env
        if ($this->client->getContainer()->has('security.token_storage')) {
            $tokenStorage = $this->client->getContainer()->get('security.token_storage');
            assert($tokenStorage instanceof \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface);
            $tokenStorage->setToken(null);
        }

        // Try to access a protected route with only text/html accept header
        // Use an admin route that requires authentication
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/getAllUsers',
            [],
            [],
            [
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_USER_AGENT' => 'Mozilla/5.0',
            ],
        );

        // Admin routes redirect to login for unauthenticated users with HTML accept header
        $this->assertStatusCode(302);
    }

    public function testLoggedInUserCanAccessProtectedRoute(): void
    {
        // Use the Base class login functionality to authenticate
        $this->logInSession('i.myself');

        // Try to access a simple protected route
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');

        // Should succeed with 200 status
        $this->assertStatusCode(200);
    }

    public function testLoginPageRendersCorrectly(): void
    {
        // Ensure kernel booted in setUp (if any) is shut down before creating a new client
        self::ensureKernelShutdown();

        $kernelBrowser = self::createClient();
        // Use the crawler provided by the client request
        $kernelBrowser->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/login');

        $this->assertResponseIsSuccessful(); // Asserts 2xx status code

        // Check the JS config for the form URL
        $content = $kernelBrowser->getResponse()->getContent();

        // The form is created with ExtJS, so check for the right script elements
        self::assertStringContainsString('Ext.form.Panel', (string) $content);
        self::assertStringContainsString("name: '_username'", (string) $content);
        self::assertStringContainsString("name: '_password'", (string) $content);
        self::assertStringContainsString("name: '_csrf_token'", (string) $content);
        // Ensure the form URL now points to /login
        self::assertStringContainsString('url: "/login"', (string) $content);
    }

    #[\PHPUnit\Framework\Attributes\Group('network')]
    public function testLogoutClearsAuthenticationAndReturnsForbidden(): void
    {
        // When a user is logged in
        $this->logInSession('unittest');

        // They should be able to access a protected route
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');
        $this->assertStatusCode(200);

        // Get CSRF token for logout (required with CSRF protection enabled)
        if ($this->serviceContainer === null) {
            throw new \RuntimeException('Service container not initialized');
        }
        $csrfTokenManager = $this->serviceContainer->get('security.csrf.token_manager');
        assert($csrfTokenManager instanceof \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface);
        $csrfToken = $csrfTokenManager->getToken('logout')->getValue();

        // After logging out with CSRF token
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/logout',
            ['_csrf_token' => $csrfToken],
        );

        // The user should be redirected
        self::assertTrue($this->client->getResponse()->isRedirect());

        // Ensure token cleared to avoid sticky authentication across requests
        $tokenStorage = $this->client->getContainer()->get('security.token_storage');
        assert($tokenStorage instanceof \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface);
        self::assertNull($tokenStorage->getToken());

        // Try to access a protected route again with browser-like headers
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/getUsers',
            [],
            [],
            ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        );

        // Should redirect to login when not authenticated
        $this->assertStatusCode(302);
    }
}
