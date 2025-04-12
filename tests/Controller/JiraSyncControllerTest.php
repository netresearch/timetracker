<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class JiraSyncControllerTest extends AbstractWebTestCase
{
    public function testSyncProjectSubticketsAction(): void
    {
        // Test syncing project subtickets
        $this->client->request('GET', '/projects/syncsubtickets');
        $this->assertStatusCode(200);

        // Check structure of response
        $this->assertJsonStructure([
            'success' => true
        ]);
    }

    public function testSyncProjectSubticketsForSpecificProjectAction(): void
    {
        // Create a project to test with
        $this->connection->query('INSERT INTO `projects`
            (`name`, `customer_id`, `ticket_system_id`, `active`, `jira_id`)
            VALUES ("Project to Sync", 1, 1, 1, "SYNC")');
        $projectId = $this->connection->lastInsertId();

        // Test syncing specific project subtickets
        $this->client->request('GET', "/projects/$projectId/syncsubtickets");
        $this->assertStatusCode(200);

        // Check structure of response
        $this->assertJsonStructure([
            'success' => true,
            'subtickets' => []
        ]);
    }

    public function testSyncAllProjectsAsDevAction(): void
    {
        // Set user as developer
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        // Developers should be able to sync all projects if they have certain permissions
        $this->client->request('GET', '/projects/syncsubtickets');

        // This might be allowed depending on your app's permissions
        // Adjust the assertion based on your application's actual behavior
        $this->assertStatusCode(200);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }

    public function testJiraSyncLogAction(): void
    {
        // Test accessing the sync log
        $this->client->request('GET', '/admin/jirasync/log');
        $this->assertStatusCode(200);

        // Check structure of response
        $this->assertJsonStructure([
            'logs' => []
        ]);
    }

    public function testJiraSyncLogActionAsDev(): void
    {
        // Set user as developer
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        // Check if developers can access logs (likely restricted)
        $this->client->request('GET', '/admin/jirasync/log');

        // Typically devs would not have access to admin logs
        $this->assertStatusCode(401);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }

    public function testTriggerManualSyncAction(): void
    {
        // Test triggering a manual sync
        $this->client->request('POST', '/admin/jirasync/trigger', [
            'project_id' => 1
        ]);
        $this->assertStatusCode(200);

        // Check structure of response
        $this->assertJsonStructure([
            'success' => true,
            'message' => 'Sync job scheduled'
        ]);
    }

    public function testTriggerManualSyncActionAsDev(): void
    {
        // Set user as developer
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        // Developers typically can't trigger admin actions
        $this->client->request('POST', '/admin/jirasync/trigger', [
            'project_id' => 1
        ]);

        // Check they are unauthorized
        $this->assertStatusCode(401);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }
}
