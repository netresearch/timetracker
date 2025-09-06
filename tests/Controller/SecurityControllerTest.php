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
    public function testAccessToProtectedRouteReturnsForbidden(): void
    {
        // Clear session to simulate not being logged in
        $session = $this->client->getContainer()->get('session');
        $session->clear();
        $session->save();

        // Also clear the security token to ensure full logout in test env
        if ($this->client->getContainer()->has('security.token_storage')) {
            $this->client->getContainer()->get('security.token_storage')->setToken(null);
        }

        // Try to access a protected route with only text/html accept header
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/controlling/export',
            [],
            [],
            [
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_USER_AGENT' => 'Mozilla/5.0',
            ]
        );

        // Should return forbidden (improved security behavior)
        $this->assertStatusCode(403);
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
        self::assertStringContainsString('Ext.form.Panel', $content);
        self::assertStringContainsString("name: '_username'", $content);
        self::assertStringContainsString("name: '_password'", $content);
        self::assertStringContainsString("name: '_csrf_token'", $content);
        // Ensure the form URL now points to /login
        self::assertStringContainsString('url: "/login"', $content);
    }

    #[\PHPUnit\Framework\Attributes\Group('network')]
    public function testLogoutClearsAuthenticationAndReturnsForbidden(): void
    {
        // When a user is logged in
        $this->logInSession('unittest');

        // They should be able to access a protected route
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');
        $this->assertStatusCode(200);

        // After logging out
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/logout');

        // The user should be redirected
        self::assertTrue($this->client->getResponse()->isRedirect());

        // Ensure token cleared to avoid sticky authentication across requests
        $tokenStorage = $this->client->getContainer()->get('security.token_storage');
        self::assertNull($tokenStorage->getToken());

        // Try to access a protected route again with browser-like headers
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/getUsers',
            [],
            [],
            ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']
        );

        // Should return forbidden (improved security behavior)
        $this->assertStatusCode(403);
    }
}
