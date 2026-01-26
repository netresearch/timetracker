<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\JsonResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Unit tests for JsonResponse.
 *
 * @internal
 */
#[CoversClass(JsonResponse::class)]
final class JsonResponseTest extends TestCase
{
    public function testConstructorWithNullContent(): void
    {
        $response = new JsonResponse(null);

        self::assertSame('null', $response->getContent());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testConstructorWithArrayContent(): void
    {
        $data = ['key' => 'value', 'number' => 42];
        $response = new JsonResponse($data);

        self::assertSame(json_encode($data), $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testConstructorWithStringContent(): void
    {
        $response = new JsonResponse('simple string');

        self::assertSame('"simple string"', $response->getContent());
    }

    public function testConstructorWithCustomStatus(): void
    {
        $response = new JsonResponse(['error' => 'Not found'], 404);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testConstructorWithCustomHeaders(): void
    {
        $response = new JsonResponse([], 200, ['X-Custom-Header' => 'custom-value']);

        self::assertSame('custom-value', $response->headers->get('X-Custom-Header'));
        // Content-Type should still be set to application/json
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testConstructorWithNestedArrayContent(): void
    {
        $data = [
            'users' => [
                ['id' => 1, 'name' => 'John'],
                ['id' => 2, 'name' => 'Jane'],
            ],
            'meta' => ['total' => 2],
        ];
        $response = new JsonResponse($data);

        self::assertSame(json_encode($data), $response->getContent());
    }

    public function testConstructorWithBooleanContent(): void
    {
        $responseTrue = new JsonResponse(true);
        $responseFalse = new JsonResponse(false);

        self::assertSame('true', $responseTrue->getContent());
        self::assertSame('false', $responseFalse->getContent());
    }

    public function testConstructorWithNumericContent(): void
    {
        $responseInt = new JsonResponse(42);
        $responseFloat = new JsonResponse(3.14);

        self::assertSame('42', $responseInt->getContent());
        self::assertSame('3.14', $responseFloat->getContent());
    }

    // ==================== send() tests ====================

    /**
     * @runInSeparateProcess
     */
    public function testSendSetsContentTypeHeader(): void
    {
        $response = new JsonResponse(['test' => 'data']);

        // Remove the Content-Type header to test that send() re-adds it
        $response->headers->remove('Content-Type');

        // We can't actually call send() in a test environment without separate process
        // But we can verify the header handling logic by checking what happens
        // when Content-Type is not set
        self::assertFalse($response->headers->has('Content-Type'));

        // Call send() - this will set the header in the send() method
        ob_start();
        $returnedResponse = $response->send();
        ob_end_clean();

        // The send() method should have set the Content-Type header
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame($response, $returnedResponse);
    }

    public function testSendReturnsStaticInstance(): void
    {
        $response = new JsonResponse(['data' => true]);

        // Don't actually send (which would output), just verify the method signature
        // by checking the fluent interface works via reflection
        $reflectionMethod = new ReflectionMethod(JsonResponse::class, 'send');
        $returnType = $reflectionMethod->getReturnType();

        self::assertNotNull($returnType);
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('static', $returnType->getName());
    }
}
