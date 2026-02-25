<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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
        $statusCode = $this->client->getResponse()->getStatusCode();
        // Any response status code confirms the test infrastructure works
        self::assertGreaterThan(0, $statusCode);
    }
}
