<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;
use Tests\Traits\HttpRequestTestTrait;

use const PHP_VERSION;

/**
 * @internal
 *
 * @coversNothing
 */
final class GetStatusActionTest extends AbstractWebTestCase
{
    use HttpRequestTestTrait;

    public function testReturnsDiagnosticsForAdmin(): void
    {
        // AbstractWebTestCase logs in as 'unittest', whose type is ADMIN.
        $this->getJson('/admin/status');
        $this->assertStatusCode(200);

        $json = $this->getJsonResponse($this->client->getResponse());
        foreach (['app', 'php', 'symfony', 'packages', 'database', 'config'] as $section) {
            self::assertArrayHasKey($section, $json);
        }
        self::assertIsArray($json['php']);
        self::assertIsArray($json['database']);
        self::assertSame(PHP_VERSION, $json['php']['version']);
        self::assertArrayHasKey('platform', $json['database']);

        // Never leaks credentials: no password anywhere, no DB user key.
        self::assertStringNotContainsStringIgnoringCase('password', (string) json_encode($json));
        self::assertArrayNotHasKey('user', $json['database']);
    }

    public function testForbiddenForNonAdmin(): void
    {
        $this->logInSession('developer'); // type DEV — not an admin
        $this->getJson('/admin/status');
        $this->assertStatusCode(403);
    }
}
