<?php

declare(strict_types=1);

namespace Tests\Traits;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_merge;
use function json_encode;

/**
 * HTTP client functionality trait.
 * 
 * Provides HTTP client setup and request helpers
 * for test cases making HTTP requests.
 */
trait HttpClientTrait
{
    protected KernelBrowser $client;
    
    protected $serviceContainer;

    /**
     * Initialize HTTP client for testing.
     */
    protected function initializeHttpClient(): void
    {
        // Reuse kernel between tests for performance; client reboot disabled below
        $this->client = static::createClient();
        // Convert kernel exceptions to HTTP responses (e.g., 422 from MapRequestPayload)
        $this->client->catchExceptions(true);
        // Use the test container to access private services like test.session
        $this->serviceContainer = static::getContainer();
    }

    /**
     * Helper method to create JSON request.
     */
    protected function createJsonRequest(
        string $method,
        string $uri,
        array $content = [],
        array $headers = [],
    ): KernelBrowser {
        // Use the client created in setUp()
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            array_merge([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], $headers),
            [] !== $content ? json_encode($content) : null,
        );

        // Return the same client instance used for the request
        return $this->client;
    }

    /**
     * Returns the kernel class to use for these tests.
     */
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }
}