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
        $session = $this->client->getContainer()->get('session');
        $session->clear();
        $session->save();

        // Try to access a protected route
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling/export');

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
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');

        // Should succeed with 200 status
        $this->assertStatusCode(200);
    }

    public function testLoginPageRendersCorrectly(): void
    {
        // Ensure kernel booted in setUp (if any) is shut down before creating a new client
        self::ensureKernelShutdown();

        $kernelBrowser = static::createClient();
        // Use the crawler provided by the client request
        $kernelBrowser->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/login');

        $this->assertResponseIsSuccessful(); // Asserts 2xx status code

        // Check the JS config for the form URL
        $content = $kernelBrowser->getResponse()->getContent();

        // The form is created with ExtJS, so check for the right script elements
        $this->assertStringContainsString('Ext.form.Panel', $content);
        $this->assertStringContainsString("name: '_username'", $content);
        $this->assertStringContainsString("name: '_password'", $content);
        $this->assertStringContainsString("name: '_csrf_token'", $content);
        // Ensure the form URL now points to /login
        $this->assertStringContainsString('url: "/login"', $content);
    }

    #[\PHPUnit\Framework\Attributes\Group('network')]
    public function testLogoutReturnsToLogin(): void
    {
        // When a user is logged in
        $this->logInSession('unittest');

        // They should be able to access a protected route
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');
        $this->assertStatusCode(200);

        // After logging out
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/logout');

        // The user should be redirected
        $this->assertTrue($this->client->getResponse()->isRedirect());

        // Ensure token cleared to avoid sticky authentication across requests
        $tokenStorage = $this->client->getContainer()->get('security.token_storage');
        $this->assertNull($tokenStorage->getToken());

        // Try to access a protected route again
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');

        // Should be redirected to login
        $this->assertStatusCode(302);
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }
}
