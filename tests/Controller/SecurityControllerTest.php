<?php

namespace Tests\Controller;

use Tests\Base;

class SecurityControllerTest extends Base
{
    // We'll test a mock login page instead of the actual login route
    // to avoid environment variable dependencies

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
}
