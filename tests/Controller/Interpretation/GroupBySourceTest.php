<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Interpretation;

use Tests\AbstractWebTestCase;

use function assert;
use function is_array;

/**
 * ADR-025 Task 13: controlling rollups slice by source and never fold human and
 * agent hours together — agent time is a distinct column beside the human total.
 *
 * @internal
 *
 * @coversNothing
 */
final class GroupBySourceTest extends AbstractWebTestCase
{
    /**
     * @return array{human: array<string, mixed>, agent: array<string, mixed>, ticket: string}
     */
    private function seedHumanAndAgentEntry(string $day): array
    {
        self::assertNotNull($this->connection);
        $ticket = 'SRC-' . bin2hex(random_bytes(4));

        // Human 60 min + agent 180 min on the SAME ticket/day/project — a naive
        // rollup would fold them into 240 min; the split must keep 60 vs 180.
        $this->connection->executeStatement(
            "INSERT INTO entries (day, start, end, customer_id, project_id, activity_id, description, ticket, duration, user_id, class, synced_to_ticketsystem, internal_jira_ticket_original_key, source)
             VALUES (:day, '08:00:00', '09:00:00', 1, 1, 1, 'human', :ticket, 60, 1, 1, 0, '', 'human')",
            ['day' => $day, 'ticket' => $ticket],
        );
        $this->connection->executeStatement(
            "INSERT INTO entries (day, start, end, customer_id, project_id, activity_id, description, ticket, duration, user_id, class, synced_to_ticketsystem, internal_jira_ticket_original_key, source)
             VALUES (:day, '09:00:00', '12:00:00', 1, 1, 1, 'agent', :ticket, 180, 1, 1, 0, '', 'agent')",
            ['day' => $day, 'ticket' => $ticket],
        );

        return ['human' => [], 'agent' => [], 'ticket' => $ticket];
    }

    public function testGroupByTicketKeepsHumanAndAgentInDistinctColumns(): void
    {
        $seed = $this->seedHumanAndAgentEntry('2099-09-09');

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/ticket', [
            'user' => 1,
            'ticket' => $seed['ticket'],
        ]);
        $this->assertStatusCode(200);

        $json = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($json);
        self::assertCount(1, $json, 'the unique ticket isolates a single bucket');

        $bucket = $json[0];
        assert(is_array($bucket));
        self::assertIsNumeric($bucket['hours']);
        self::assertIsNumeric($bucket['agentHours']);
        self::assertEqualsWithDelta(1.0, (float) $bucket['hours'], 0.001, 'human hours = 60 min, agent 180 not folded in');
        self::assertEqualsWithDelta(3.0, (float) $bucket['agentHours'], 0.001, 'agent hours = 180 min in its own column');
    }

    public function testGroupByWorktimeKeepsHumanAndAgentInDistinctColumns(): void
    {
        $seed = $this->seedHumanAndAgentEntry('2099-09-10');

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/time', [
            'user' => 1,
            'ticket' => $seed['ticket'],
        ]);
        $this->assertStatusCode(200);

        $json = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($json);
        self::assertCount(1, $json, 'both entries fall on the same day bucket');

        $bucket = $json[0];
        assert(is_array($bucket));
        self::assertIsNumeric($bucket['hours']);
        self::assertIsNumeric($bucket['agentHours']);
        self::assertEqualsWithDelta(1.0, (float) $bucket['hours'], 0.001, 'the worked bar is human labour only');
        self::assertEqualsWithDelta(3.0, (float) $bucket['agentHours'], 0.001, 'agent wall-clock sits in a distinct column');
    }
}
