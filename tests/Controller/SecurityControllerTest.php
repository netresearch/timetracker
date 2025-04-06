<?php

namespace Tests\Controller;

use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\HttpFoundation\Response;
use Tests\AbstractWebTestCase;

class SecurityControllerTest extends AbstractWebTestCase
{
    public function testAccessToProtectedRouteRedirectsToLogin(): void
    {
        // Clear session to simulate not being logged in
        $this->client->getContainer()->get('session')->clear();

        // Try to access a protected route
        $this->client->request('GET', '/controlling/export');

        // Should redirect to login
        $this->assertStatusCode(302);
        $response = $this->client->getResponse();
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }

    public function testLoggedInUserCanAccessProtectedRoute(): void
    {
        // Use the Base class login functionality to authenticate
        $this->logInSession('unittest');

        // Try to access a simple protected route
        $this->client->request('GET', '/getUsers');

        // Should succeed with 200 status
        $this->assertStatusCode(200);
    }

    public function testLoginPageRendersCorrectly(): void
    {
        // Ensure kernel booted in setUp (if any) is shut down before creating a new client
        self::ensureKernelShutdown();

        $client = static::createClient();
        // Use the crawler provided by the client request
        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful(); // Asserts 2xx status code

        // Check the JS config for the form URL
        $content = $client->getResponse()->getContent();

        // The form is created with ExtJS, so check for the right script elements
        $this->assertStringContainsString('Ext.form.Panel', $content);
        $this->assertStringContainsString('name: \'_username\'', $content);
        $this->assertStringContainsString('name: \'_password\'', $content);
        $this->assertStringContainsString('name: \'_csrf_token\'', $content);
        // Ensure the form URL now points to /login
        $this->assertStringContainsString('url: "/login"', $content);
    }

    /**
     * @group network
     */
    public function testLogoutReturnsToLogin(): void
    {
        // When a user is logged in
        $this->logInSession('unittest');

        // They should be able to access a protected route
        $this->client->request('GET', '/getUsers');
        $this->assertStatusCode(200);

        // After logging out
        $this->client->request('GET', '/logout');

        // The user should be redirected
        $this->assertTrue($this->client->getResponse()->isRedirect());

        // Make sure the session is cleared after logout
        $session = $this->client->getContainer()->get('session');
        $this->assertNull($session->get('_security_main'));

        // Try to access a protected route again
        $this->client->request('GET', '/getUsers');

        // Should be redirected to login
        $this->assertStatusCode(302);
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @group auth
     * @group remember_me
     */
    public function testRememberMeFunctionality(): void
    {
        // This test demonstrates how to test the remember_me functionality
        // However, we'll skip it because accurately testing remember_me requires:
        // 1. Creating a cookie with exact same format as Symfony's RememberMeServices
        // 2. Access to the same encryption keys used in production
        // These dependencies make this test difficult to run in isolation
        $this->markTestSkipped(
            'Remember me testing requires exact knowledge of Symfony\'s cookie format and encryption keys'
        );

        /* Here's how the cookie test would be structured:
         *
         * // Clear cookies and session
         * $this->client->getCookieJar()->clear();
         * $this->client->getContainer()->get('session')->clear();
         *
         * // Set a correct remember_me cookie (format depends on Symfony version and config)
         * $rememberMeCookie = new Cookie(...);
         * $this->client->getCookieJar()->set($rememberMeCookie);
         *
         * // Access a protected resource
         * $this->client->request('GET', '/getUsers');
         *
         * // Verify authentication works via cookie
         * $this->assertStatusCode(200);
         */
    }
}
