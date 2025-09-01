<?php

declare(strict_types=1);

namespace Tests\Response;

use App\Response\Error;
use PHPUnit\Framework\TestCase;

class ErrorResponseTest extends TestCase
{
    public function testConstructSetsMessageAndStatus(): void
    {
        $error = new Error('Not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND, $error->getStatusCode(), (string) $error->getContent());

        $data = json_decode((string) $error->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame('Not found', $data['message'] ?? null);
        $this->assertArrayNotHasKey('forwardUrl', $data);
    }

    public function testConstructAddsForwardUrlWhenProvided(): void
    {
        $error = new Error('Forbidden', \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN, '/login');
        $data = json_decode((string) $error->getContent(), true);
        $this->assertSame('/login', $data['forwardUrl'] ?? null);
    }
}
