<?php

declare(strict_types=1);

namespace Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base test case for standard PHPUnit tests.
 */
abstract class TestCase extends WebTestCase
{
    /**
     * Set up before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Add common setup functionality
    }

    /**
     * Tear down after each test.
     */
    protected function tearDown(): void
    {
        // Add common teardown functionality

        parent::tearDown();
    }

    /**
     * Create a client with a default Authorization header.
     */
    protected function createAuthenticatedClient(string $username = 'test', string $password = 'password'): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/login',
            ['username' => $username, 'password' => $password]
        );

        $this->assertResponseRedirects();

        return $client;
    }

    /**
     * Helper method to create JSON request.
     */
    protected function createJsonRequest(
        string $method,
        string $uri,
        array $content = [],
        array $headers = []
    ): \Symfony\Bundle\FrameworkBundle\KernelBrowser {
        $client = static::createClient();
        $client->request(
            $method,
            $uri,
            [],
            [],
            array_merge([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], $headers),
            $content ? json_encode($content) : null
        );

        return $client;
    }
}
