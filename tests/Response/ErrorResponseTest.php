<?php

declare(strict_types=1);

namespace Tests\Response;

use App\Response\Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

use function assert;
use function is_array;
use function json_decode;

/**
 * Unit tests for Error response class.
 *
 * @internal
 */
#[CoversClass(Error::class)]
final class ErrorResponseTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructSetsMessageAndStatus(): void
    {
        $error = new Error('Not found', Response::HTTP_NOT_FOUND);

        self::assertSame(Response::HTTP_NOT_FOUND, $error->getStatusCode());

        $data = json_decode((string) $error->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Not found', $data['message'] ?? null);
        self::assertArrayNotHasKey('forwardUrl', $data);
    }

    public function testConstructAddsForwardUrlWhenProvided(): void
    {
        $error = new Error('Forbidden', Response::HTTP_FORBIDDEN, '/login');

        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));
        self::assertSame('/login', $data['forwardUrl'] ?? null);
    }

    public function testConstructDoesNotAddEmptyForwardUrl(): void
    {
        $error = new Error('Error', Response::HTTP_BAD_REQUEST, '');

        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));
        self::assertArrayNotHasKey('forwardUrl', $data);
    }

    public function testConstructDoesNotAddNullForwardUrl(): void
    {
        $error = new Error('Error', Response::HTTP_BAD_REQUEST, null);

        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));
        self::assertArrayNotHasKey('forwardUrl', $data);
    }

    public function testConstructNormalizesZeroStatusCode(): void
    {
        $error = new Error('Error', 0);

        self::assertSame(400, $error->getStatusCode());
    }

    public function testConstructNormalizesNegativeStatusCode(): void
    {
        $error = new Error('Error', -1);

        self::assertSame(400, $error->getStatusCode());
    }

    public function testConstructSetsJsonContentType(): void
    {
        $error = new Error('Error', Response::HTTP_BAD_REQUEST);

        self::assertSame('application/json', $error->headers->get('Content-Type'));
    }

    // ==================== Exception handling tests ====================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructIncludesExceptionWhenDisplayErrorsEnabled(): void
    {
        ini_set('display_errors', '1');

        $exception = new RuntimeException('Test exception', 42);
        $error = new Error('Error occurred', Response::HTTP_INTERNAL_SERVER_ERROR, null, $exception);

        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));

        self::assertArrayHasKey('exception', $data);
        self::assertIsArray($data['exception']);
        self::assertSame('Test exception', $data['exception']['message']);
        self::assertSame(RuntimeException::class, $data['exception']['class']);
        self::assertSame(42, $data['exception']['code']);
        self::assertArrayHasKey('file', $data['exception']);
        self::assertArrayHasKey('line', $data['exception']);
        self::assertArrayHasKey('trace', $data['exception']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructIncludesExceptionWhenDisplayErrorsSetToZero(): void
    {
        // Note: The implementation only excludes exception when display_errors is
        // empty string or false. Setting '0' still includes the exception.
        ini_set('display_errors', '0');

        $exception = new RuntimeException('Test exception');
        $error = new Error('Error occurred', Response::HTTP_INTERNAL_SERVER_ERROR, null, $exception);

        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));

        // '0' is truthy for the string check, so exception is still included
        self::assertArrayHasKey('exception', $data);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructExcludesExceptionWhenDisplayErrorsEmpty(): void
    {
        ini_set('display_errors', '');

        $exception = new RuntimeException('Test exception');
        $error = new Error('Error occurred', Response::HTTP_INTERNAL_SERVER_ERROR, null, $exception);

        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));

        self::assertArrayNotHasKey('exception', $data);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructIncludesPreviousException(): void
    {
        ini_set('display_errors', '1');

        $previous = new RuntimeException('Previous error', 1);
        $exception = new RuntimeException('Current error', 2, $previous);
        $error = new Error('Error occurred', Response::HTTP_INTERNAL_SERVER_ERROR, null, $exception);

        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));

        self::assertArrayHasKey('exception', $data);
        self::assertIsArray($data['exception']);
        self::assertSame('Current error', $data['exception']['message']);
        self::assertArrayHasKey('previous', $data['exception']);
        $previous = $data['exception']['previous'];
        self::assertIsArray($previous);
        self::assertSame('Previous error', $previous['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructHandlesNullThrowable(): void
    {
        ini_set('display_errors', '1');

        $error = new Error('Error occurred', Response::HTTP_INTERNAL_SERVER_ERROR, null, null);

        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));

        self::assertArrayHasKey('exception', $data);
        self::assertNull($data['exception']);
    }
}
