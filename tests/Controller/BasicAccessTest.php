<?php

namespace Tests\Controller;

use Symfony\Component\BrowserKit\Cookie;
use Tests\AbstractWebTestCase;

class BasicAccessTest extends AbstractWebTestCase
{
    /**
     * Test based on SecurityControllerTest which works correctly
     */
    public function testLoginThenAccessEndpoint(): void
    {
        // Use the working test approach from SecurityControllerTest
        // Reset database to ensure clean state
        #$this->resetDatabase();

        // Clear session first
        $this->client->getContainer()->get('session')->clear();
        $this->client->getCookieJar()->clear();
        // Ensure unauthenticated by clearing the token storage as well
        if ($this->client->getContainer()->has('security.token_storage')) {
            $this->client->getContainer()->get('security.token_storage')->setToken(null);
        }

        // Try to access protected route - should fail
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllUsers');
        $this->assertStatusCode(302); // Should redirect to login

        // Use the Base class login functionality to authenticate
        $this->logInSession('unittest');

        // Perform a lightweight request to apply the session cookie to the browser
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');

        // Check authentication is working with a simple endpoint
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');
        $this->assertStatusCode(200);

        // Reinforce authentication just before admin endpoint
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');

        // Now try the AdminController endpoint
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllUsers');

        // Assert we get a successful response, not a redirect
        $this->assertStatusCode(200);
    }
}
