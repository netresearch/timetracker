<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class TeamControllerTest extends AbstractWebTestCase
{
    public function testGetTeamsAction(): void
    {
        // Test getting all teams
        $this->client->request('GET', '/getAllTeams');
        $this->assertStatusCode(200);

        // Check structure of response
        $this->assertJsonStructure([
            'team' => [
                'id' => 1,
                'name' => 'Test Team',
                'lead_user_id' => 1,
            ],
        ]);
    }

    public function testSaveTeamAction(): void
    {
        // Prepare team data for creation
        $teamData = [
            'id' => '',
            'name' => 'New Test Team',
            'lead_user_id' => 1
        ];

        // Make the request
        $this->client->request('POST', '/team/save', $teamData);
        $this->assertStatusCode(200);

        // Check if the team was saved correctly
        $query = 'SELECT * FROM `teams` WHERE `name` = "New Test Team"';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $this->assertCount(1, $result);
        $this->assertEquals('New Test Team', $result[0]['name']);
        $this->assertEquals(1, $result[0]['lead_user_id']);
    }

    public function testUpdateTeamAction(): void
    {
        // First, create a team to update
        $this->connection->query('INSERT INTO `teams`
            (`name`, `lead_user_id`)
            VALUES ("Team to Update", 1)');
        $teamId = $this->connection->lastInsertId();

        // Prepare update data
        $updateData = [
            'id' => $teamId,
            'name' => 'Updated Team Name',
            'lead_user_id' => 2
        ];

        // Make the update request
        $this->client->request('POST', '/team/save', $updateData);
        $this->assertStatusCode(200);

        // Verify the update in the database
        $query = "SELECT * FROM `teams` WHERE `id` = $teamId";
        $result = $this->connection->query($query)->fetchAssociative();

        $this->assertEquals('Updated Team Name', $result['name']);
        $this->assertEquals(2, $result['lead_user_id']);
    }

    public function testSaveTeamActionAsDev(): void
    {
        // Set user as developer (not admin)
        $this->logInSession('unittest');

        $teamData = [
            'id' => '',
            'name' => 'Dev Team',
            'lead_user_id' => 1
        ];

        // Make the request
        $this->client->request('POST', '/team/save', $teamData);

        // Developers should not be able to create teams
        $this->assertStatusCode(401);
    }

    public function testDeleteTeamAction(): void
    {
        // Create a team to delete
        $this->connection->query('INSERT INTO `teams`
            (`name`, `lead_user_id`)
            VALUES ("Team to Delete", 1)');
        $teamId = $this->connection->lastInsertId();

        // Make delete request
        $this->client->request('POST', '/team/delete', ['id' => $teamId]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['success' => true]);

        // Verify deletion in database
        $query = "SELECT COUNT(*) as count FROM `teams` WHERE `id` = $teamId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int)$result['count']);
    }

    public function testDeleteTeamActionAsDev(): void
    {
        // Create a team to delete
        $this->connection->query('INSERT INTO `teams`
            (`name`, `lead_user_id`)
            VALUES ("Dev Team to Delete", 1)');
        $teamId = $this->connection->lastInsertId();

        // Set user as developer
        $this->logInSession('unittest');

        // Make delete request
        $this->client->request('POST', '/team/delete', ['id' => $teamId]);

        // Should fail for developers
        $this->assertStatusCode(401);

        // Verify team still exists
        $query = "SELECT COUNT(*) as count FROM `teams` WHERE `id` = $teamId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(1, (int)$result['count']);
    }
}
