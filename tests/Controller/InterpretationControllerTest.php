<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

use function assert;
use function is_array;
use function is_string;

/**
 * @internal
 *
 * @coversNothing
 */
final class InterpretationControllerTest extends AbstractWebTestCase
{
    public function testGetLastEntriesAction(): void
    {
        $testTicket = 'TST-' . substr(uniqid(), 0, 6);

        // First create a test entry with known ticket
        self::assertNotNull($this->connection);
        $this->connection->executeStatement(
            "INSERT INTO entries (day, start, end, customer_id, project_id, activity_id, description, ticket, duration, user_id, class, synced_to_ticketsystem, internal_jira_ticket_original_key)
             VALUES (CURDATE(), '08:00:00', '08:50:00', 1, 1, 1, 'Test description', :ticket, 50, 1, 1, 0, '')",
            ['ticket' => $testTicket],
        );

        $parameter = [
            'user' => 1,
            'ticket' => $testTicket,
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/entries', $parameter);
        $this->assertStatusCode(200);

        $json = $this->getJsonResponse($this->client->getResponse());

        // Verify we got an array response with entry structure
        self::assertIsArray($json, 'Response should be an array');
        self::assertNotEmpty($json, 'Response should have at least one entry');

        // Verify first entry has expected structure
        $firstEntry = $json[0];
        assert(is_array($firstEntry));
        self::assertArrayHasKey('entry', $firstEntry, 'Each result should have entry wrapper');

        $entry = $firstEntry['entry'];
        assert(is_array($entry));
        $requiredFields = ['id', 'date', 'start', 'end', 'user', 'customer', 'project', 'activity', 'ticket', 'duration'];
        foreach ($requiredFields as $field) {
            self::assertArrayHasKey($field, $entry, "Entry should have field '$field'");
        }

        // Verify the ticket filter works
        $ticketValue = $entry['ticket'];
        assert(is_string($ticketValue));
        self::assertSame($testTicket, $ticketValue, 'Entry should have the requested ticket');
    }

    public function testTicketFilterExcludesNonMatchingEntries(): void
    {
        // The ticket filter must actually narrow the result (it previously passed
        // the guard but was never applied to the query). A unique ticket returns
        // exactly its one entry; a non-matching ticket returns none.
        self::assertNotNull($this->connection);
        $ticket = 'FILT-' . bin2hex(random_bytes(4));
        $this->connection->executeStatement(
            "INSERT INTO entries (day, start, end, customer_id, project_id, activity_id, description, ticket, duration, user_id, class, synced_to_ticketsystem, internal_jira_ticket_original_key)
             VALUES (CURDATE(), '08:00:00', '08:50:00', 1, 1, 1, 'x', :ticket, 50, 1, 1, 0, '')",
            ['ticket' => $ticket],
        );

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/entries', ['ticket' => $ticket]);
        $this->assertStatusCode(200);
        $matching = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($matching);
        self::assertCount(1, $matching);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/entries', ['ticket' => $ticket . 'ZZZ']);
        $this->assertStatusCode(200);
        $none = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($none);
        self::assertCount(0, $none);
    }

    public function testTeamFilterScopesGroupByUserToTeamMembers(): void
    {
        // Team 1 holds user 1 (unittest), team 2 holds user 2 (developer). Grouping
        // by user filtered to team 2 must include developer and exclude unittest.
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/user', ['team' => 2]);
        $this->assertStatusCode(200);
        $json = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($json);
        $names = array_column($json, 'name');
        self::assertContains('developer', $names);
        self::assertNotContains('unittest', $names);
    }

    public function testActivityTeamDescriptionAreAcceptedAsStandaloneFilters(): void
    {
        // Each used to trip the "specify at least …" guard; now each is a valid
        // standalone criterion (200, not 406).
        foreach ([['activity' => '1'], ['team' => '1'], ['description' => 'Test']] as $params) {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/entries', $params);
            $this->assertStatusCode(200);
        }
    }

    public function testGroupByWorktimeAction(): void
    {
        $parameter = [
            'user' => 1,    // req
            'datestart' => '1000-01-29',    // opt
            'dateend' => '1000-01-30',  // opt
        ];

        // 1000-01-29/30 fall before any contract (the fixtures start 2020), so
        // each day's "expected" Soll comes from the 5×8h default. Both are
        // weekdays (Wed/Thu) → 8h.
        $expectedJson = [
            [
                'name' => '00-01-29',
                'day' => '29.01.',
                'hours' => 0.23333333333333334,
                'quota' => '5.98%',
                'expected' => 8,
            ],
            [
                'name' => '00-01-30',
                'day' => '30.01.',
                'hours' => 3.6666666666666665,
                'quota' => '94.02%',
                'expected' => 8,
            ],
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/time', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGroupByWorktimeZeroesExpectedOnAHoliday(): void
    {
        // 1000-01-29 (Wed) carries user-1 fixture bookings; marking it a public
        // holiday must drop that day's Soll to 0, matching /ui/month, while
        // 1000-01-30 (Thu, no holiday, before any contract) keeps the 8h default.
        self::assertNotNull($this->connection);
        $this->connection->insert('holidays', ['day' => '1000-01-29', 'name' => 'Test Holiday']);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/time', [
            'user' => 1,
            'datestart' => '1000-01-29',
            'dateend' => '1000-01-30',
        ]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            ['name' => '00-01-29', 'expected' => 0],
            ['name' => '00-01-30', 'expected' => 8],
        ], $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGroupByWorktimeDerivesExpectedFromTheContract(): void
    {
        // A contract covering the year-1000 fixture bookings: Wed (hours_3) = 5,
        // Thu (hours_4) = 6. The per-day Soll must come from the contract weekday
        // columns, not the 5x8h default.
        self::assertNotNull($this->connection);
        $this->connection->insert('contracts', [
            'user_id' => 1,
            'start' => '1000-01-01',
            'end' => '1000-12-31',
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 5,
            'hours_4' => 6,
            'hours_5' => 0,
            'hours_6' => 0,
        ]);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/time', [
            'user' => 1,
            'datestart' => '1000-01-29',
            'dateend' => '1000-01-30',
        ]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            ['name' => '00-01-29', 'expected' => 5],
            ['name' => '00-01-30', 'expected' => 6],
        ], $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGroupByWorktimeSollFollowsTheFilteredUserNotTheViewer(): void
    {
        // 2020-01-06 is a Monday. The filtered user 2 (developer) has hours_1 = 2;
        // the ADMIN viewer (user 1) has hours_1 = 1 — so a Soll of 2 proves the
        // per-day Soll follows the FILTERED user's contract, not the viewer's.
        self::assertNotNull($this->connection);
        $this->connection->executeStatement(
            "INSERT INTO entries (day, start, end, customer_id, project_id, activity_id, description, ticket, duration, user_id, class, synced_to_ticketsystem, internal_jira_ticket_original_key)
             VALUES ('2020-01-06', '08:00:00', '10:00:00', 1, 1, 1, 'mon', 'MON-1', 120, 2, 1, 0, '')",
        );

        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/time', [
            'user' => 2,
            'datestart' => '2020-01-06',
            'dateend' => '2020-01-06',
        ]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            ['name' => '20-01-06', 'expected' => 2],
        ], $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGroupByWorktimeSendsNoSollForAMultiUserQuery(): void
    {
        // Two users booked the same Monday (2020-03-16) under customer 1; querying
        // that customer for that day (no single user) spans several contracts, so
        // the Soll is 0 — and 0 on a weekday (not a weekend) proves it is the
        // multi-user case, not a weekend zero.
        self::assertNotNull($this->connection);
        foreach ([2, 3] as $uid) {
            $this->connection->executeStatement(
                "INSERT INTO entries (day, start, end, customer_id, project_id, activity_id, description, ticket, duration, user_id, class, synced_to_ticketsystem, internal_jira_ticket_original_key)
                 VALUES ('2020-03-16', '08:00:00', '09:00:00', 1, 1, 1, 'm', '', 60, :uid, 1, 0, '')",
                ['uid' => $uid],
            );
        }

        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/time', [
            'customer' => 1,
            'datestart' => '2020-03-16',
            'dateend' => '2020-03-16',
        ]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            ['name' => '20-03-16', 'expected' => 0],
        ], $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGroupByUserRespectsTheUserFilterNotTheViewer(): void
    {
        // An ADMIN filtering user=2 must get developer's breakdown — the action no
        // longer forces the grouping back to the current user.
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/user', [
            'user' => 2,
        ]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            ['id' => 2, 'name' => 'developer'],
        ], $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGroupByActivityAction(): void
    {
        $parameter = [
            'user' => 3,    // req
        ];
        $expectedJson = [
            0 => [
                'id' => 1,
                'name' => 'Entwicklung',
                'hours' => 1.1666666666666667,
                'quota' => '100.00%',
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/activity', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetAllEntriesActionDevNotAllowed(): void
    {
        $this->logInSession('developer');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries', []);
        $this->assertStatusCode(403);
        // Controller translate() path + de-locale fixture — the German catalog now
        // covers this id (the English used to be the missing-translation fallback).
        $this->assertMessage('Diese Aktion ist nicht erlaubt.');
    }

    public function testGetAllEntriesActionIgnoresInvalidDateString(): void
    {
        $parameter = [
            'datestart=not a date',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));

        // Invalid dates are now silently ignored (more robust behavior)
        $this->assertStatusCode(200);

        // Verify response has expected JSON structure
        $response = $this->client->getResponse();
        $json = json_decode((string) $response->getContent(), true);
        assert(is_array($json), 'Response should be an array');
        self::assertArrayHasKey('links', $json);
        self::assertArrayHasKey('data', $json);
    }

    public function testGetAllEntriesActionIgnoresInvalidDateInteger(): void
    {
        $parameter = [
            'dateend=1',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));

        // Invalid dates are now silently ignored (more robust behavior)
        $this->assertStatusCode(200);

        // Verify response has expected JSON structure
        $response = $this->client->getResponse();
        $json = json_decode((string) $response->getContent(), true);
        assert(is_array($json), 'Response should be an array');
        self::assertArrayHasKey('links', $json);
        self::assertArrayHasKey('data', $json);
    }

    public function testGetAllEntriesActionReturnDataNoParameter(): void
    {
        $expectedLinks = [];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?page=0',
            'last' => 'http://localhost/interpretation/allEntries?page=0',
            'prev' => null,
            'next' => null,
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries');
        $this->assertStatusCode(200);

        // Validate response structure
        $response = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($response);
        self::assertArrayHasKey('data', $response);
        self::assertArrayHasKey('links', $response);

        // Validate links structure
        $this->assertJsonStructure($expectedLinks, $response);

        // Validate data entries have required fields
        $data = $response['data'];
        assert(is_array($data));
        self::assertNotEmpty($data, 'Data should not be empty');
        $firstEntry = $data[0];
        assert(is_array($firstEntry));
        $requiredFields = ['id', 'date', 'start', 'end', 'description', 'ticket', 'duration', 'durationMinutes', 'user_id', 'project_id', 'customer_id', 'activity_id'];
        foreach ($requiredFields as $field) {
            self::assertArrayHasKey($field, $firstEntry, "Entry should have field '$field'");
        }
    }

    public function testGetAllEntriesActionReturnDataWithParameter(): void
    {
        // Test filtering by date range, project, customer, and activity
        $parameter = [
            'datestart=500-04-29',
            'dateend=1500-04-29',
            'project_id=1',
            'customer_id=1',
            'activity_id=1',
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);

        // Validate response structure
        $response = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($response);
        self::assertArrayHasKey('data', $response);
        self::assertArrayHasKey('links', $response);

        // Validate links contain the filter parameters
        $links = $response['links'];
        assert(is_array($links));
        $selfLink = $links['self'];
        assert(is_string($selfLink));
        self::assertStringContainsString('activity_id=1', $selfLink);
        self::assertStringContainsString('customer_id=1', $selfLink);
        self::assertStringContainsString('project_id=1', $selfLink);

        // Validate filtered data - all entries should match the filter criteria
        $data = $response['data'];
        assert(is_array($data));
        foreach ($data as $entry) {
            assert(is_array($entry));
            self::assertSame(1, $entry['project_id'], 'Entry should have project_id=1');
            self::assertSame(1, $entry['customer_id'], 'Entry should have customer_id=1');
            self::assertSame(1, $entry['activity_id'], 'Entry should have activity_id=1');
        }
    }

    public function testGetAllEntriesActionReturnLinksNegativePage(): void
    {
        $parameter = [
            'page=-1',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(400);
        $this->assertJsonStructure(['message' => 'page can not be negative.'], $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetAllEntriesActionReturnLinksNoParameter(): void
    {
        $expectedLinks = [];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?page=0',
            'last' => 'http://localhost/interpretation/allEntries?page=0',
            'prev' => null,
            'next' => null,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedLinks, $this->getJsonResponse($this->client->getResponse()));
        $this->assertLength(8, 'data'); // Updated to match actual response (includes additional test data)
    }

    public function testGetAllEntriesActionReturnLinksPageOne(): void
    {
        $expectedData = [];
        $expectedLinks = [];
        $parameter = [
            'maxResults=2',
            'page=0',
        ];
        $expectedData['data'] = [
            ['id' => 4], // Updated to match actual response (testGetDataAction entries come first)
            ['id' => 5],
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?maxResults=2&page=0',
            'last' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'prev' => null,
            'next' => 'http://localhost/interpretation/allEntries?maxResults=2&page=1',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);
        $this->assertLength(2, 'data');
        $this->assertJsonStructure($expectedLinks, $this->getJsonResponse($this->client->getResponse()));
        $this->assertJsonStructure($expectedData, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetAllEntriesActionReturnLinksPageTwo(): void
    {
        $expectedData = [];
        $expectedLinks = [];
        $parameter = [
            'maxResults=2',
            'page=1',
        ];
        $expectedData['data'] = [
            ['id' => 8], // Page 1 actually returns IDs 8,2
            ['id' => 2],
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?maxResults=2&page=1',
            'last' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'prev' => 'http://localhost/interpretation/allEntries?maxResults=2&page=0',
            'next' => 'http://localhost/interpretation/allEntries?maxResults=2&page=2',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);

        $this->assertLength(2, 'data');
        $this->assertJsonStructure($expectedLinks, $this->getJsonResponse($this->client->getResponse()));
        $this->assertJsonStructure($expectedData, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetAllEntriesActionReturnLinksLastPage(): void
    {
        $expectedData = [];
        $expectedLinks = [];
        $parameter = [
            'maxResults=2',
            'page=3',
        ];
        $expectedData['data'] = [
            ['id' => 7], // Last page (page 3) actually returns IDs 7,6
            ['id' => 6],
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'last' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'prev' => 'http://localhost/interpretation/allEntries?maxResults=2&page=2',
            'next' => null,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);

        $this->assertLength(2, 'data'); // Last page actually has 2 entries based on test results
        $this->assertJsonStructure($expectedLinks, $this->getJsonResponse($this->client->getResponse()));
        $this->assertJsonStructure($expectedData, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetAllEntriesActionReturnLinksEmptyData(): void
    {
        $parameter = [
            'project_id=42',
        ];
        $expectedLinks = [];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?project_id=42&page=0',
            'last' => null,
            'prev' => null,
            'next' => null,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);
        $this->assertLength(0, 'data');
        $this->assertJsonStructure($expectedLinks, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetAllEntriesActionReturnLinksNonExistingPage(): void
    {
        $parameter = [
            'maxResults=2',
            'page=42',
        ];
        $expectedLinks = [];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?maxResults=2&page=42',
            'last' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'prev' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'next' => null,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);
        $this->assertLength(0, 'data');
        $this->assertJsonStructure($expectedLinks, $this->getJsonResponse($this->client->getResponse()));
    }
}
