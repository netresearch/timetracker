<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testSendSetsCorsHeaders(): void
    {
        $response = new Response('ok', 200);
        // call send() which sets headers then parent::send(); but sending output is okay in test
        // we assert headers are set before send() returns
        $response->send();

        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('GET, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertSame('3600', $response->headers->get('Access-Control-Max-Age'));
    }
}
