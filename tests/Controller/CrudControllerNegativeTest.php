<?php

declare(strict_types=1);

namespace Tests;

use App\Entity\Project;

/**
 * @internal
 *
 * @coversNothing
 */
final class CrudControllerNegativeTest extends AbstractWebTestCase
{
    /**
     * Invalid ticket format should return 406.
     */
    public function testSaveActionInvalidTicketFormat(): void
    {
        // Ensure logged in
        $this->logInSession('unittest');

        // Use a project that enforces ticket format via jiraId
        $parameter = [
            'start' => '09:00:00',
            'end' => '10:00:00',
            'date' => '2024-01-02',
            'project_id' => 1,
            'customer_id' => 1,
            'activity_id' => 1,
            'ticket' => 'invalid-ticket',
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/tracking/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('message', $data);
        self::assertNotEmpty($data['message']);
    }

    /**
     * Ticket prefix not matching project's Jira ID should return 406.
     */
    public function testSaveActionInvalidTicketPrefix(): void
    {
        $this->logInSession('unittest');

        // SA is configured in fixtures; use mismatching prefix
        $parameter = [
            'start' => '09:00:00',
            'end' => '10:00:00',
            'date' => '2024-01-03',
            'project_id' => 1,
            'customer_id' => 1,
            'activity_id' => 1,
            'ticket' => 'WRONG-123',
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/tracking/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('message', $data);
        self::assertNotEmpty($data['message']);
        // Depending on data, it might reach prefix check; format check comes first in controller
    }

    /**
     * Inactive project should return 406.
     */
    public function testSaveActionInactiveProject(): void
    {
        $this->logInSession('unittest');

        // Project id 2 is inactive in fixtures (see DefaultControllerTest data expectations)
        $parameter = [
            'start' => '09:00:00',
            'end' => '10:00:00',
            'date' => '2024-01-04',
            'project_id' => 2,
            'customer_id' => 1,
            'activity_id' => 1,
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/tracking/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('message', $data);
        self::assertNotEmpty($data['message']);
    }
}
