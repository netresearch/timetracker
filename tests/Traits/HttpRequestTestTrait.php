<?php

declare(strict_types=1);

namespace Tests\Traits;

use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP request helper trait for test classes.
 * 
 * Provides fluent interface methods for common HTTP request patterns,
 * reducing boilerplate and enabling method chaining for cleaner tests.
 */
trait HttpRequestTestTrait
{
    /**
     * Make a GET request and return self for method chaining.
     */
    /**
     * @param array<string, string> $headers
     */
    protected function getJson(string $url, array $headers = []): self
    {
        $defaultHeaders = ['HTTP_ACCEPT' => 'application/json'];
        $this->client->request(Request::METHOD_GET, $url, [], [], array_merge($defaultHeaders, $headers));
        return $this;
    }

    /**
     * Make a GET request without JSON headers.
     */
    /**
     * @param array<string, string> $headers
     */
    protected function get(string $url, array $headers = []): self
    {
        $this->client->request(Request::METHOD_GET, $url, [], [], $headers);
        return $this;
    }

    /**
     * Make a POST request with JSON headers and return self for method chaining.
     */
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function postJson(string $url, array $data = [], array $headers = []): self
    {
        $defaultHeaders = ['HTTP_ACCEPT' => 'application/json'];
        $this->client->request(Request::METHOD_POST, $url, $data, [], array_merge($defaultHeaders, $headers));
        return $this;
    }

    /**
     * Make a POST request without JSON headers.
     */
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function post(string $url, array $data = [], array $headers = []): self
    {
        $this->client->request(Request::METHOD_POST, $url, $data, [], $headers);
        return $this;
    }

    /**
     * Assert successful response (2xx status code).
     */
    protected function assertSuccessfulResponse(): self
    {
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue($statusCode >= 200 && $statusCode < 300, 
            "Expected successful response, got {$statusCode}");
        return $this;
    }

    /**
     * Assert forbidden response (403 status code).
     */
    protected function assertForbidden(): self
    {
        $this->assertStatusCode(403);
        return $this;
    }

    /**
     * Assert unauthorized response (401 status code).
     */
    protected function assertUnauthorized(): self
    {
        $this->assertStatusCode(401);
        return $this;
    }

    /**
     * Assert redirect response (3xx status code).
     */
    protected function assertRedirect(?string $expectedLocation = null): self
    {
        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect(), 'Expected redirect response');

        if ($expectedLocation !== null) {
            $location = $response->headers->get('Location');
            self::assertIsString($location, 'Location header should be a string');
            $this->assertStringContainsString($expectedLocation, $location);
        }
        return $this;
    }

    /**
     * Assert JSON response structure matches expected array.
     * @param array<string, mixed> $expected
     */
    protected function assertJsonEquals(array $expected): self
    {
        $response = $this->client->getResponse();
        $json = $this->getJsonResponse($response);
        $this->assertJsonStructure($expected, $json);
        return $this;
    }

    /**
     * Assert response contains specific message using fluent interface.
     */
    protected function assertHasMessage(string $expectedMessage): self
    {
        $response = $this->client->getResponse();
        $this->assertResponseMessage($expectedMessage, $response);
        return $this;
    }
}