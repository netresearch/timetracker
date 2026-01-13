<?php

declare(strict_types=1);

namespace Tests\Traits;

use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_merge;
use function is_array;
use function json_encode;
use function sprintf;

/**
 * HTTP client functionality trait.
 *
 * Provides HTTP client setup and request helpers
 * for test cases making HTTP requests.
 */
trait HttpClientTrait
{
    protected KernelBrowser $client;

    protected ?ContainerInterface $serviceContainer = null;

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
     *
     * @param array<string, mixed>  $content
     * @param array<string, string> $headers
     */
    protected function createJsonRequest(
        string $method,
        string $uri,
        array $content = [],
        array $headers = [],
    ): KernelBrowser {
        $jsonContent = null;
        if ([] !== $content) {
            $encodedContent = json_encode($content);
            if (false === $encodedContent) {
                throw new RuntimeException('Failed to encode JSON content');
            }
            $jsonContent = $encodedContent;
        }

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
            $jsonContent,
        );

        // Return the same client instance used for the request
        return $this->client;
    }

    /**
     * Assert HTTP status code matches expected value.
     */
    protected function assertStatusCode(int $expectedCode): void
    {
        $response = $this->client->getResponse();
        $actualCode = $response->getStatusCode();

        self::assertSame(
            $expectedCode,
            $actualCode,
            sprintf(
                'Expected HTTP status code %d, got %d. Response: %s',
                $expectedCode,
                $actualCode,
                (false !== $response->getContent() && '' !== $response->getContent()) ? $response->getContent() : '(empty)',
            ),
        );
    }

    /**
     * Assert response message matches expected value.
     * Works with both HTML responses and JSON responses containing 'message' property.
     */
    protected function assertMessage(string $expectedMessage): void
    {
        $response = $this->client->getResponse();
        $content = $response->getContent();

        if (false === $content) {
            self::fail('Response content is empty');
        }

        // Check if response is JSON and has a message property
        if ('application/json' === $response->headers->get('Content-Type')) {
            $jsonData = json_decode($content, true);
            if (is_array($jsonData) && isset($jsonData['message'])) {
                self::assertSame($expectedMessage, $jsonData['message'], 'JSON message should match expected value');

                return;
            }
        }

        // For non-JSON responses or JSON without message property, check direct content
        self::assertSame($expectedMessage, $content, 'Response content should match expected message');
    }

    /**
     * Returns the kernel class to use for these tests.
     */
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }
}
