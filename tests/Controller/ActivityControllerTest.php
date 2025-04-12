<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class ActivityControllerTest extends AbstractWebTestCase
{
    public function testGetActivitiesAction(): void
    {
        // Migrated from AdminControllerTest
        $this->client->request('GET', '/activities');
        $this->assertStatusCode(200);

        $expectedJson = [
            'activities' => [
                [
                    'id' => 1,
                    'name' => 'Activity 1',
                    'description' => 'Description for Activity 1',
                    'color' => '#ff0000',
                    'active' => true,
                ],
            ],
        ];
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetActivitiesActionDev(): void
    {
        // Migrated from AdminControllerTest
        // Set user as developer (not admin)
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        $this->client->request('GET', '/activities');
        $this->assertStatusCode(403);
        $this->assertJsonStructure(['error' => 'Sie haben keine Berechtigung, Aktivitäten zu verwalten.']);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }

    public function testSaveActivityAction(): void
    {
        // Migrated from AdminControllerTest
        $parameter = [
            'id' => '',
            'name' => 'Test Activity',
            'description' => 'Test Activity Description',
            'color' => '#00ff00',
            'active' => true,
        ];

        $this->client->request('POST', '/admin/activity/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Die Aktivität wurde erfolgreich gespeichert.']);

        // Verify in DB
        $query = 'SELECT * FROM `activities` WHERE `name` = "Test Activity"';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $this->assertCount(1, $result);
        $this->assertEquals('Test Activity', $result[0]['name']);
        $this->assertEquals('Test Activity Description', $result[0]['description']);
        $this->assertEquals('#00ff00', $result[0]['color']);
        $this->assertEquals(1, (int)$result[0]['active']);
    }

    public function testSaveActivityActionDev(): void
    {
        // Migrated from AdminControllerTest
        // Set user as developer (not admin)
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        $parameter = [
            'id' => '',
            'name' => 'Dev Test Activity',
            'description' => 'Dev Test Activity Description',
            'color' => '#0000ff',
            'active' => true,
        ];

        $this->client->request('POST', '/admin/activity/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertJsonStructure(['error' => 'Sie haben keine Berechtigung, Aktivitäten zu bearbeiten.']);

        // Verify in DB - should not exist
        $query = 'SELECT * FROM `activities` WHERE `name` = "Dev Test Activity"';
        $result = $this->connection->query($query)->fetchAllAssociative();
        $this->assertCount(0, $result);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }

    public function testUpdateActivityAction(): void
    {
        // Migrated from AdminControllerTest
        // Create an activity to update
        $this->connection->query('INSERT INTO `activities`
            (`name`, `description`, `color`, `active`)
            VALUES ("Update Test Activity", "Update Test Description", "#cccccc", 1)');
        $activityId = $this->connection->lastInsertId();

        $parameter = [
            'id' => $activityId,
            'name' => 'Updated Activity Name',
            'description' => 'Updated Activity Description',
            'color' => '#dddddd',
            'active' => false,
        ];

        $this->client->request('POST', '/admin/activity/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Die Aktivität wurde erfolgreich gespeichert.']);

        // Verify in DB
        $query = "SELECT * FROM `activities` WHERE `id` = $activityId";
        $result = $this->connection->query($query)->fetchAssociative();

        $this->assertEquals('Updated Activity Name', $result['name']);
        $this->assertEquals('Updated Activity Description', $result['description']);
        $this->assertEquals('#dddddd', $result['color']);
        $this->assertEquals(0, (int)$result['active']);
    }

    public function testUpdateActivityActionDev(): void
    {
        // Migrated from AdminControllerTest
        // Create an activity to update
        $this->connection->query('INSERT INTO `activities`
            (`name`, `description`, `color`, `active`)
            VALUES ("Dev Update Test Activity", "Dev Update Test Description", "#eeeeee", 1)');
        $activityId = $this->connection->lastInsertId();

        // Set user as developer (not admin)
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        $parameter = [
            'id' => $activityId,
            'name' => 'Dev Updated Activity Name',
            'description' => 'Dev Updated Activity Description',
            'color' => '#ffffff',
            'active' => false,
        ];

        $this->client->request('POST', '/admin/activity/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertJsonStructure(['error' => 'Sie haben keine Berechtigung, Aktivitäten zu bearbeiten.']);

        // Verify DB was not updated
        $query = "SELECT * FROM `activities` WHERE `id` = $activityId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals('Dev Update Test Activity', $result['name']);
        $this->assertEquals('Dev Update Test Description', $result['description']);
        $this->assertEquals('#eeeeee', $result['color']);
        $this->assertEquals(1, (int)$result['active']);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }

    public function testDeleteActivityAction(): void
    {
        // Migrated from AdminControllerTest
        // Create an activity to delete
        $this->connection->query('INSERT INTO `activities`
            (`name`, `description`, `color`, `active`)
            VALUES ("Delete Test Activity", "Delete Test Description", "#aaaaaa", 1)');
        $activityId = $this->connection->lastInsertId();

        $parameter = [
            'id' => $activityId,
        ];

        $this->client->request('POST', '/admin/activity/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Die Aktivität wurde gelöscht.']);

        // Verify in DB
        $query = "SELECT COUNT(*) as count FROM `activities` WHERE `id` = $activityId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int)$result['count']);
    }

    public function testDeleteActivityActionDev(): void
    {
        // Migrated from AdminControllerTest
        // Create an activity to delete
        $this->connection->query('INSERT INTO `activities`
            (`name`, `description`, `color`, `active`)
            VALUES ("Dev Delete Test Activity", "Dev Delete Test Description", "#bbbbbb", 1)');
        $activityId = $this->connection->lastInsertId();

        // Set user as developer (not admin)
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        $parameter = [
            'id' => $activityId,
        ];

        $this->client->request('POST', '/admin/activity/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertJsonStructure(['error' => 'Sie haben keine Berechtigung, Aktivitäten zu bearbeiten.']);

        // Verify in DB that it was not deleted
        $query = "SELECT COUNT(*) as count FROM `activities` WHERE `id` = $activityId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(1, (int)$result['count']);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }
}
