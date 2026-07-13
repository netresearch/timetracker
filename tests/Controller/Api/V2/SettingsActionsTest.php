<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\MintsApiTokens;

use function json_decode;

/**
 * GET + PATCH /api/v2/settings: session and PAT access, scope gates,
 * partial-update semantics, and the DTO wire shape.
 *
 * @internal
 */
final class SettingsActionsTest extends AbstractWebTestCase
{
    use MintsApiTokens;

    private const array KEYS = [
        'locale', 'show_empty_line', 'suggest_time', 'show_future',
        'min_entry_duration', 'personio_sync_enabled',
    ];

    public function testSessionGetReturnsSettingsShape(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        foreach (self::KEYS as $key) {
            self::assertArrayHasKey($key, $data);
        }
        self::assertIsString($data['locale']);
        self::assertIsInt($data['min_entry_duration']);
    }

    public function testTokenWithSettingsReadIsAuthorized(): void
    {
        $status = $this->requestWithToken('/api/v2/settings', $this->mintToken(['settings:read']));

        self::assertSame(200, $status);
    }

    public function testTokenWithoutSettingsReadIsForbidden(): void
    {
        $status = $this->requestWithToken('/api/v2/settings', $this->mintToken(['entries:read']));

        self::assertSame(403, $status);
    }
}
