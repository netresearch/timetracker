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

/**
 * GET /api/v2/day (ADR-022 Phase 2): the caller's own bookings for one day.
 *
 * @internal
 */
final class GetDayActionTest extends AbstractWebTestCase
{
    use CreatesTestEntries;
    use MintsApiTokens;

    public function testSessionRequestReturnsTheRequestedDay(): void
    {
        // The trait books 2026-07-06 for the session user.
        $this->createEntryFor('unittest', ticket: 'SA-11', description: 'day summary entry');

        $this->client->request(Request::METHOD_GET, '/api/v2/day?date=2026-07-06');
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('2026-07-06', $data['date']);
        self::assertIsList($data['entries']);
        self::assertNotEmpty($data['entries']);
        self::assertSame(60, $data['total_minutes']);
        self::assertSame(1, $data['count']);
    }

    public function testDayResponseSplitsHumanAndAgentMinutes(): void
    {
        // A human 60-min entry plus an agent 120-min entry on the same day: the
        // response must report them separately, never as one merged total.
        $this->createEntryFor('unittest', ticket: 'SA-13', description: 'human day entry');

        $agent = $this->createEntryFor('unittest', ticket: 'SA-14', description: 'agent day entry');
        $agent->setSource(\App\Enum\EntrySource::AGENT)->setDuration(120);
        $this->testEntityManager()->flush();

        $this->client->request(Request::METHOD_GET, '/api/v2/day?date=2026-07-06');
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(60, $data['human_minutes'], 'human total excludes the agent 120');
        self::assertSame(120, $data['agent_minutes'], 'agent wall-clock surfaced separately');
        self::assertSame(60, $data['total_minutes'], 'total_minutes stays the human figure (back-compat)');
        self::assertSame(1, $data['count'], 'the day list stays human-only');
    }

    public function testForeignEntriesAreNotIncluded(): void
    {
        // Another user's booking on the same day must not appear for the caller.
        $this->createEntryFor('developer', ticket: 'SA-12', description: 'foreign day entry');

        $this->client->request(Request::METHOD_GET, '/api/v2/day?date=2026-07-06');
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(0, $data['count']);
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/day?date=not-a-date');

        $this->assertStatusCode(422);
    }

    public function testTokenWithEntriesReadIsAuthorized(): void
    {
        $status = $this->requestWithToken('/api/v2/day', $this->mintToken(['entries:read']));

        self::assertSame(200, $status);
    }

    public function testTokenWithoutEntriesReadIsForbidden(): void
    {
        $status = $this->requestWithToken('/api/v2/day', $this->mintToken(['reporting:read']));

        self::assertSame(403, $status);
    }
}
