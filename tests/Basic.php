<?php

declare(strict_types=1);

namespace Tests;

/**
 * @internal
 *
 * @coversNothing
 */
final class Basic extends AbstractWebTestCase
{
    public function testBasic(): void
    {
        // Basic smoke test - verify the test client can make a request
        $this->client->request('GET', '/');
        $response = $this->client->getResponse();
        self::assertNotNull($response);
    }
}
