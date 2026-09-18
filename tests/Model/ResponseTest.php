<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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
    public function testSendEmitsNoCorsHeaders(): void
    {
        $response = new Response('ok', \Symfony\Component\HttpFoundation\Response::HTTP_OK);
        $this->expectOutputString('ok');
        $response->send();

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($response->headers->get('Access-Control-Allow-Methods'));
        self::assertNull($response->headers->get('Access-Control-Max-Age'));
    }
}
