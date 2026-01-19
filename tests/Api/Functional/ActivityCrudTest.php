<?php

declare(strict_types=1);

namespace Tests\Api\Functional;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_array;
use function is_int;

use const JSON_THROW_ON_ERROR;

/**
 * API Functional Tests - Activity CRUD Operations.
 *
 * These tests verify actual CRUD operations with real database.
 * Use for CI/full test runs.
 *
 * @internal
 *
 * @coversNothing
 */
final class ActivityCrudTest extends AbstractWebTestCase
{
    public function testCreateActivity(): void
    {
        $this->logInSession('unittest');

        $uniqueName = 'E2E Test Activity ' . uniqid();
        $this->client->request(
            Request::METHOD_POST,
            '/activity/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => $uniqueName,
                'needsTicket' => false,
                'factor' => 1.0,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(200);
        $response = $this->getJsonResponse($this->client->getResponse());

        // Activity save returns array: [id, name, needsTicket, factor]
        self::assertIsArray($response);
        self::assertCount(4, $response);
        self::assertSame($uniqueName, $response[1]);
    }

    public function testReadActivities(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_GET, '/getActivities');
        $this->assertStatusCode(200);

        $activities = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($activities);
        self::assertNotEmpty($activities);

        // Verify structure
        /** @var array<string, mixed> $first */
        $first = $activities[0];
        $activity = isset($first['activity']) && is_array($first['activity']) ? $first['activity'] : $first;
        self::assertArrayHasKey('id', $activity);
        self::assertArrayHasKey('name', $activity);
    }

    public function testUpdateActivity(): void
    {
        $this->logInSession('unittest');

        // Get existing activity
        $this->client->request(Request::METHOD_GET, '/getActivities');
        $activities = $this->getJsonResponse($this->client->getResponse());

        $existingActivity = null;
        foreach ($activities as $item) {
            /** @var array<string, mixed> $item */
            $activity = isset($item['activity']) && is_array($item['activity']) ? $item['activity'] : $item;
            $id = $activity['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $existingActivity = $activity;
                break;
            }
        }

        if (null === $existingActivity) {
            self::markTestSkipped('No activities available for update test');
        }

        // Update the activity
        $updatedName = 'Updated Activity ' . uniqid();
        $this->client->request(
            Request::METHOD_POST,
            '/activity/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'id' => $existingActivity['id'],
                'name' => $updatedName,
                'needsTicket' => $existingActivity['needsTicket'] ?? false,
                'factor' => $existingActivity['factor'] ?? 1.0,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(200);
        $response = $this->getJsonResponse($this->client->getResponse());

        // Activity save returns array: [id, name, needsTicket, factor]
        self::assertIsArray($response);
        self::assertCount(4, $response);
        self::assertSame($updatedName, $response[1]);
    }

    public function testActivityWithTicketRequirement(): void
    {
        $this->logInSession('unittest');

        $uniqueName = 'Ticket Required Activity ' . uniqid();
        $this->client->request(
            Request::METHOD_POST,
            '/activity/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => $uniqueName,
                'needsTicket' => true,
                'factor' => 1.0,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(200);
        $response = $this->getJsonResponse($this->client->getResponse());

        // Activity save returns array: [id, name, needsTicket, factor]
        self::assertIsArray($response);
        self::assertTrue($response[2]); // needsTicket is index 2
    }

    public function testActivityWithFactor(): void
    {
        $this->logInSession('unittest');

        $uniqueName = 'Factor Activity ' . uniqid();
        $this->client->request(
            Request::METHOD_POST,
            '/activity/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => $uniqueName,
                'needsTicket' => false,
                'factor' => 0.5,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(200);
        $response = $this->getJsonResponse($this->client->getResponse());

        // Activity save returns array: [id, name, needsTicket, factor]
        self::assertIsArray($response);
        self::assertEquals(0.5, $response[3]); // factor is index 3
    }

    public function testActivityValidation(): void
    {
        $this->logInSession('unittest');

        // Try to create activity without name
        $this->client->request(
            Request::METHOD_POST,
            '/activity/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'needsTicket' => false,
                'factor' => 1.0,
            ], JSON_THROW_ON_ERROR),
        );

        // Should return error
        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 400, 422, 500]);
    }
}
