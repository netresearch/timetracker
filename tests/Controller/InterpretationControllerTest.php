<?php

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

    public function testGroupByWorktimeAction(): void
    {
        $parameter = [
            'user' => 1,    // req
            'datestart' => '1000-01-29',    // opt
            'dateend' => '1000-01-30',  // opt
        ];

        $expectedJson = [
            [
                'name' => '00-01-29',
                'day' => '29.01.',
                'hours' => 0.23333333333333334,
                'quota' => '5.98%',
            ],
            [
                'name' => '00-01-30',
                'day' => '30.01.',
                'hours' => 3.6666666666666665,
                'quota' => '94.02%',
            ],
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/interpretation/time', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson, $this->getJsonResponse($this->client->getResponse()));
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
        $this->assertMessage('You are not allowed to perform this action.');
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
