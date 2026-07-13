<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use Doctrine\Persistence\ManagerRegistry;
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

    public function testPatchSingleFieldLeavesOthersUntouched(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $before = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($before);
        self::assertIsBool($before['suggest_time']);

        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', [
            'suggest_time' => !$before['suggest_time'],
        ]);
        $this->assertStatusCode(200);

        $after = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($after);
        self::assertSame(!$before['suggest_time'], $after['suggest_time']);
        // Every other field is untouched — the partial-update guarantee.
        foreach (['locale', 'show_empty_line', 'show_future', 'min_entry_duration', 'personio_sync_enabled'] as $key) {
            self::assertSame($before[$key], $after[$key], $key);
        }

        // Re-read from the database (identity map dropped): the response above
        // was serialized from the in-memory entity, so only this proves flush().
        $this->clearEntityManager();
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $persisted = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($persisted);
        self::assertSame(!$before['suggest_time'], $persisted['suggest_time']);
    }

    public function testPatchFullPayloadPersistsAllFields(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $before = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($before);

        $payload = [
            'locale' => 'en',
            'show_empty_line' => true,
            'suggest_time' => false,
            'show_future' => true,
            'min_entry_duration' => 15,
            'personio_sync_enabled' => false,
        ];
        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', $payload);
        $this->assertStatusCode(200);

        $after = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($after);
        self::assertSame($payload, $after);

        // Re-read from the database (identity map dropped) — proves flush().
        $this->clearEntityManager();
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $persisted = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($payload, $persisted);
    }

    public function testPatchNormalizesLocale(): void
    {
        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', ['locale' => 'xx']);
        $this->assertStatusCode(200);
        $after = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($after);
        // Unknown locales normalize to a supported one — never persisted raw.
        self::assertContains($after['locale'], ['en', 'de', 'es', 'fr', 'ru']);
    }

    public function testPatchMinDurationOutOfRangeIsRejected(): void
    {
        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', ['min_entry_duration' => 100000]);
        $this->assertStatusCode(422);
    }

    public function testTokenWithSettingsWriteMayPatch(): void
    {
        $status = $this->patchJsonWithToken(
            '/api/v2/settings',
            $this->mintToken(['settings:read', 'settings:write']),
            [],
        );

        self::assertSame(200, $status);
    }

    public function testTokenWithoutSettingsWriteMayNotPatch(): void
    {
        $status = $this->patchJsonWithToken(
            '/api/v2/settings',
            $this->mintToken(['settings:read']),
            [],
        );

        self::assertSame(403, $status);
    }

    /**
     * Drop the Doctrine identity map so the next request re-reads from the
     * database (per-test isolation is a rolled-back transaction, so flushed
     * writes ARE visible inside the test — unflushed ones vanish here).
     */
    private function clearEntityManager(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $doctrine->getManager()->clear();
    }
}
