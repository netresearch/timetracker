<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\Response;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ResponseTest extends TestCase
{
    public function testSendSetsCorsHeaders(): void
    {
        $response = new Response('ok', \Symfony\Component\HttpFoundation\Response::HTTP_OK);
        // call send() which sets headers then parent::send(); but sending output is okay in test
        // we assert headers are set before send() returns
        $this->expectOutputString('ok');
        $response->send();

        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('GET, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('3600', $response->headers->get('Access-Control-Max-Age'));
    }
}
