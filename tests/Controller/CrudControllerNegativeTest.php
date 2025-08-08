<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\CrudController;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use PHPUnit\Framework\MockObject\MockObject;

class CrudControllerNegativeTest extends AbstractWebTestCase
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
            'project' => 1,
            'customer' => 1,
            'activity' => 1,
            'ticket' => 'invalid-ticket',
        ];

        $this->client->request('POST', '/tracking/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage("The ticket's format is not recognized.");
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
            'project' => 1,
            'customer' => 1,
            'activity' => 1,
            'ticket' => 'WRONG-123',
        ];

        $this->client->request('POST', '/tracking/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage("The ticket's format is not recognized.");
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
            'project' => 2,
            'customer' => 1,
            'activity' => 1,
        ];

        $this->client->request('POST', '/tracking/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('This project is inactive and cannot be used for booking.');
    }
}


