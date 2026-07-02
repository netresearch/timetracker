<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use RuntimeException;
use Tests\AbstractWebTestCase;

use function assert;

/**
 * @internal
 *
 * @coversNothing
 */
final class SecurityControllerTest extends AbstractWebTestCase
{
    /**
     * Override setUp to not automatically log in for this test class.
     *
     * @phpstan-ignore phpunit.callParent (Intentionally bypassing parent to avoid auto-login)
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

        self::assertResponseIsSuccessful(); // Asserts 2xx status code

        $content = (string) $kernelBrowser->getResponse()->getContent();

        // The login is now a SolidJS app (login.tsx) mounted on #login, with the
        // config injected for the client and a server-rendered no-JS fallback form.
        self::assertStringContainsString('id="login"', $content);
        self::assertStringContainsString('window.LOGIN_CONFIG', $content);
        // The fallback form (and the SolidJS form) use the firewall field names.
        self::assertStringContainsString('name="_username"', $content);
        self::assertStringContainsString('name="_password"', $content);
        self::assertStringContainsString('name="_csrf_token"', $content);
        self::assertStringContainsString('action="/login"', $content);
        // ExtJS is no longer loaded on the login page.
        self::assertStringNotContainsString('Ext.form.Panel', $content);
        self::assertStringNotContainsString('ext-all.js', $content);
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
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        $csrfTokenManager = $this->serviceContainer->get('security.csrf.token_manager');
        assert($csrfTokenManager instanceof \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface);
        $csrfToken = $csrfTokenManager->getToken('logout')->getValue();

        // After logging out with CSRF token. The stateless CSRF validator
        // accepts the token only alongside a same-origin fetch-metadata
        // header, which BrowserKit does not send on its own.
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/logout',
            ['_csrf_token' => $csrfToken],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
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
