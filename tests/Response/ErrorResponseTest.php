<?php

declare(strict_types=1);

namespace Tests\Response;

use App\Response\Error;
use PHPUnit\Framework\TestCase;

class ErrorResponseTest extends TestCase
{
    public function testConstructSetsMessageAndStatus(): void
    {
        $error = new Error('Not found', 404);
        $this->assertSame(404, $error->getStatusCode());

        $data = json_decode((string) $error->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame('Not found', $data['message'] ?? null);
        $this->assertArrayNotHasKey('forwardUrl', $data);
    }

    public function testConstructAddsForwardUrlWhenProvided(): void
    {
        $error = new Error('Forbidden', 403, '/login');
        $data = json_decode((string) $error->getContent(), true);
        $this->assertSame('/login', $data['forwardUrl'] ?? null);
    }
}


