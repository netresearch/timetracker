<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class ProjectControllerTest extends AbstractWebTestCase
{
    public function testGetAllProjectsAction(): void
    {
        // Test getting all projects
        $this->client->request('GET', '/getAllProjects');
        $this->assertStatusCode(200);

        // Check structure of response
        $this->assertJsonStructure([
            'project' => [
                'id' => 1,
                'name' => 'Test Project',
                'customer' => 1,
                'ticket_system' => 1,
                'active' => 1,
            ],
        ]);
    }

    public function testGetAllProjectsActionWithCustomer(): void
    {
        // Test getting projects filtered by customer
        $this->client->request('GET', '/getAllProjects?customer=1');
        $this->assertStatusCode(200);

        // Check that results contain only projects for the specified customer
        $responseContent = json_decode($this->client->getResponse()->getContent(), true);

        // Check each project belongs to customer 1
        foreach ($responseContent as $projectData) {
            $this->assertEquals(1, $projectData['project']['customer']);
        }
    }

    public function testSaveProjectAction(): void
    {
        // Prepare project data for creation
        $projectData = [
            'id' => '',
            'name' => 'New Test Project',
            'customer' => 1,
            'ticket_system' => 1,
            'project_lead' => 1,
            'technical_lead' => 1,
            'jiraId' => 'TEST',
            'jiraTicket' => 'TEST-123',
            'active' => 1,
            'global' => 0,
            'estimation' => 100,
            'billing' => 'hourly',
            'cost_center' => 'CC123',
            'offer' => 'OFF123',
            'additionalInformationFromExternal' => '',
            'internalJiraTicketSystem' => '',
            'internalJiraProjectKey' => ''
        ];

        // Make the request
        $this->client->request('POST', '/project/save', $projectData);
        $this->assertStatusCode(200);

        // Check if the project was saved correctly
        $query = 'SELECT * FROM `projects` WHERE `name` = "New Test Project"';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $this->assertCount(1, $result);
        $this->assertEquals('New Test Project', $result[0]['name']);
        $this->assertEquals(1, $result[0]['customer_id']);
        $this->assertEquals('TEST', $result[0]['jira_id']);
        $this->assertEquals('TEST-123', $result[0]['jira_ticket']);
    }

    public function testUpdateProjectAction(): void
    {
        // First, create a project to update
        $this->connection->query('INSERT INTO `projects`
            (`name`, `customer_id`, `ticket_system_id`, `active`, `jira_id`, `jira_ticket`)
            VALUES ("Project to Update", 1, 1, 1, "PROJ", "PROJ-001")');
        $projectId = $this->connection->lastInsertId();

        // Prepare update data
        $updateData = [
            'id' => $projectId,
            'name' => 'Updated Project Name',
            'customer' => 1,
            'ticket_system' => 1,
            'project_lead' => 1,
            'technical_lead' => 1,
            'jiraId' => 'PROJ',
            'jiraTicket' => 'PROJ-002',
            'active' => 1,
            'global' => 0,
            'estimation' => 200,
            'billing' => 'fixed',
            'cost_center' => 'CC456',
            'offer' => 'OFF456',
            'additionalInformationFromExternal' => 'Additional info',
            'internalJiraTicketSystem' => '',
            'internalJiraProjectKey' => ''
        ];

        // Make the update request
        $this->client->request('POST', '/project/save', $updateData);
        $this->assertStatusCode(200);

        // Verify the update in the database
        $query = "SELECT * FROM `projects` WHERE `id` = $projectId";
        $result = $this->connection->query($query)->fetchAssociative();

        $this->assertEquals('Updated Project Name', $result['name']);
        $this->assertEquals('PROJ-002', $result['jira_ticket']);
        $this->assertEquals('fixed', $result['billing']);
        $this->assertEquals(200, $result['estimation']);
    }

    public function testSaveProjectActionAsDev(): void
    {
        // Set user as developer (not admin)
        $this->logInSession('unittest');

        $projectData = [
            'id' => '',
            'name' => 'Dev Project',
            'customer' => 1,
            'ticket_system' => 1,
            'project_lead' => 1,
            'technical_lead' => 1,
            'jiraId' => 'DEV',
            'jiraTicket' => 'DEV-123',
            'active' => 1,
            'global' => 0
        ];

        // Make the request
        $this->client->request('POST', '/project/save', $projectData);

        // Developers should not be able to create projects
        $this->assertStatusCode(401);
    }

    public function testDeleteProjectAction(): void
    {
        // Create a project to delete
        $this->connection->query('INSERT INTO `projects`
            (`name`, `customer_id`, `ticket_system_id`, `active`)
            VALUES ("Project to Delete", 1, 1, 1)');
        $projectId = $this->connection->lastInsertId();

        // Make delete request
        $this->client->request('POST', '/project/delete', ['id' => $projectId]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['success' => true]);

        // Verify deletion in database
        $query = "SELECT COUNT(*) as count FROM `projects` WHERE `id` = $projectId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int)$result['count']);
    }

    public function testDeleteProjectActionAsDev(): void
    {
        // Create a project to delete
        $this->connection->query('INSERT INTO `projects`
            (`name`, `customer_id`, `ticket_system_id`, `active`)
            VALUES ("Dev Project to Delete", 1, 1, 1)');
        $projectId = $this->connection->lastInsertId();

        // Set user as developer
        $this->logInSession('unittest');

        // Make delete request
        $this->client->request('POST', '/project/delete', ['id' => $projectId]);

        // Should fail for developers
        $this->assertStatusCode(401);

        // Verify project still exists
        $query = "SELECT COUNT(*) as count FROM `projects` WHERE `id` = $projectId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(1, (int)$result['count']);
    }

    public function testSyncAllProjectSubticketsAction(): void
    {
        // Test syncing all project subtickets
        $this->client->request('GET', '/projects/syncsubtickets');
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['success' => true]);
    }

    public function testSyncProjectSubticketsAction(): void
    {
        // Create a project to sync
        $this->connection->query('INSERT INTO `projects`
            (`name`, `customer_id`, `ticket_system_id`, `active`, `jira_id`)
            VALUES ("Project to Sync", 1, 1, 1, "SYNC")');
        $projectId = $this->connection->lastInsertId();

        // Test syncing specific project subtickets
        $this->client->request('GET', "/projects/$projectId/syncsubtickets");
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            'success' => true,
            'subtickets' => []
        ]);
    }
}
