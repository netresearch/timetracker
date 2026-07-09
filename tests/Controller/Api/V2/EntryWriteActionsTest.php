<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\CreatesTestEntries;
use Tests\Traits\MintsApiTokens;

use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * PATCH /api/v2/entries/{id} and POST /api/v2/bulk-entries (ADR-022 Phase 4).
 * Session user 'unittest' (id 1); 'developer' (id 2) owns foreign entries;
 * preset 1 'Urlaub' exists.
 *
 * @internal
 */
final class EntryWriteActionsTest extends AbstractWebTestCase
{
    use CreatesTestEntries;
    use MintsApiTokens;

    public function testPatchChangesOnlyGivenFields(): void
    {
        $entry = $this->createEntryFor('unittest', ticket: 'SA-30', description: 'before');

        $this->patchJson(sprintf('/api/v2/entries/%d', (int) $entry->getId()), ['description' => 'after']);
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['result']);
        self::assertSame('after', $data['result']['description']);
        self::assertSame('SA-30', $data['result']['ticket']); // kept
    }

    public function testPatchForeignEntryIsNotFound(): void
    {
        $entry = $this->createEntryFor('developer', ticket: 'SA-31', description: 'foreign');

        $this->patchJson(sprintf('/api/v2/entries/%d', (int) $entry->getId()), ['description' => 'hijack']);

        $this->assertStatusCode(404);
    }

    public function testPatchInvalidTimeIs422(): void
    {
        $entry = $this->createEntryFor('unittest', ticket: 'SA-32', description: 'times');

        $this->patchJson(sprintf('/api/v2/entries/%d', (int) $entry->getId()), ['start' => 'not-a-time', 'durationMinutes' => 30]);

        $this->assertStatusCode(422);
    }

    public function testBulkEntriesCreatesRange(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/api/v2/bulk-entries',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['preset_id' => 1, 'start_date' => '2026-07-06', 'end_date' => '2026-07-07', 'use_contract' => false, 'skip_weekend' => false, 'skip_holidays' => false, 'start_time' => '09:00', 'end_time' => '10:00'], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
    }

    public function testBulkEntriesUnknownPresetIs422(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/api/v2/bulk-entries',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['preset_id' => 99999, 'start_date' => '2026-07-06', 'end_date' => '2026-07-06'], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(422);
    }

    public function testPatchTokenWithoutWriteScopeIsForbidden(): void
    {
        $entry = $this->createEntryFor('unittest', ticket: 'SA-33', description: 'scope');
        $token = $this->mintToken(['entries:read']);

        $this->client->request(
            Request::METHOD_PATCH,
            sprintf('/api/v2/entries/%d', (int) $entry->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['description' => 'x'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $json
     */
    private function patchJson(string $path, array $json): void
    {
        $this->client->request(
            Request::METHOD_PATCH,
            $path,
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($json, JSON_THROW_ON_ERROR),
        );
    }
}
