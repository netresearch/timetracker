<?php

declare(strict_types=1);

namespace Tests\Response;

use App\Response\Error;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ErrorResponseTest extends TestCase
{
    public function testConstructSetsMessageAndStatus(): void
    {
        $error = new Error('Not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        self::assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND, $error->getStatusCode(), (string) $error->getContent());

        $data = json_decode((string) $error->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Not found', $data['message'] ?? null);
        self::assertArrayNotHasKey('forwardUrl', $data);
    }

    public function testConstructAddsForwardUrlWhenProvided(): void
    {
        $error = new Error('Forbidden', \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN, '/login');
        $data = json_decode((string) $error->getContent(), true);
        assert(is_array($data));
        self::assertSame('/login', $data['forwardUrl'] ?? null);
    }
}
