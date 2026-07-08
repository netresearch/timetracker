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

use function assert;
use function is_array;
use function json_decode;
use function sprintf;

/**
 * GET /api/v2/entries/{id}/summary (ADR-022): the "Info" popup aggregation,
 * owner-scoped — a foreign or unknown entry id reads as 404.
 *
 * @internal
 */
final class GetEntrySummaryActionTest extends AbstractWebTestCase
{
    use CreatesTestEntries;

    public function testOwnEntryReturnsScopesAndEstimate(): void
    {
        $entryId = (int) $this->createEntryFor('unittest', description: 'summary test entry')->getId();

        $this->client->request(Request::METHOD_GET, sprintf('/api/v2/entries/%d/summary', $entryId));
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        foreach (['customer', 'project', 'activity', 'ticket'] as $scope) {
            self::assertArrayHasKey($scope, $data);
            assert(is_array($data[$scope]));
            foreach (['scope', 'name', 'entries', 'total', 'own', 'estimation'] as $key) {
                self::assertArrayHasKey($key, $data[$scope], $scope);
            }
        }
        self::assertArrayHasKey('estimate', $data);
        assert(is_array($data['estimate']));
        self::assertArrayHasKey('status', $data['estimate']);
        self::assertArrayHasKey('warnings', $data);
    }

    public function testForeignEntryReadsAsNotFound(): void
    {
        // Owned by user 'developer' (id 2), requested by the session user
        // 'unittest' (id 1) — must be 404, not a cross-user disclosure (IDOR).
        $entryId = (int) $this->createEntryFor('developer', description: 'foreign entry')->getId();

        $this->client->request(Request::METHOD_GET, sprintf('/api/v2/entries/%d/summary', $entryId));

        $this->assertStatusCode(404);
    }

    public function testUnknownEntryIsNotFound(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/entries/999999/summary');

        $this->assertStatusCode(404);
    }
}
