<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class UserControllerTest extends AbstractWebTestCase
{
    public function testGetUsersAction(): void
    {
        // Test getting all users
        $this->client->request('GET', '/getAllUsers');
        $this->assertStatusCode(200);

        // Check structure of response
        $this->assertJsonStructure([
            'user' => [
                'id' => 1,
                'username' => 'unittest',
                'abbr' => 'UT',
                'type' => 'ROLE_ADMIN',
            ],
        ]);
    }

    public function testSaveUserAction(): void
    {
        // Prepare user data for creation
        $userData = [
            'id' => '',
            'username' => 'newuser',
            'abbr' => 'NU',
            'type' => 'ROLE_DEV',
            'locale' => 'en',
            'teams' => []
        ];

        // Make the request
        $this->client->request('POST', '/user/save', $userData);
        $this->assertStatusCode(200);

        // Check if the user was saved correctly
        $query = 'SELECT * FROM `users` WHERE `username` = "newuser"';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $this->assertCount(1, $result);
        $this->assertEquals('newuser', $result[0]['username']);
        $this->assertEquals('NU', $result[0]['abbr']);
        $this->assertEquals('ROLE_DEV', $result[0]['role']);
    }

    public function testUpdateUserAction(): void
    {
        // First, create a user to update
        $this->connection->query('INSERT INTO `users`
            (`username`, `abbr`, `role`, `locale`)
            VALUES ("testupdate", "TU", "ROLE_DEV", "en")');
        $userId = $this->connection->lastInsertId();

        // Prepare update data
        $updateData = [
            'id' => $userId,
            'username' => 'updateduser',
            'abbr' => 'UU',
            'type' => 'ROLE_CTL',
            'locale' => 'de',
            'teams' => []
        ];

        // Make the update request
        $this->client->request('POST', '/user/save', $updateData);
        $this->assertStatusCode(200);

        // Verify the update in the database
        $query = "SELECT * FROM `users` WHERE `id` = $userId";
        $result = $this->connection->query($query)->fetchAssociative();

        $this->assertEquals('updateduser', $result['username']);
        $this->assertEquals('UU', $result['abbr']);
        $this->assertEquals('ROLE_CTL', $result['role']);
        $this->assertEquals('de', $result['locale']);
    }

    public function testSaveUserActionAsDev(): void
    {
        // Set user as developer (not admin)
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        $userData = [
            'id' => '',
            'username' => 'devuser',
            'abbr' => 'DU',
            'type' => 'ROLE_DEV',
            'locale' => 'en',
            'teams' => []
        ];

        // Make the request
        $this->client->request('POST', '/user/save', $userData);

        // Developers should not be able to create users
        $this->assertStatusCode(401);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }

    public function testDeleteUserAction(): void
    {
        // Create a user to delete
        $this->connection->query('INSERT INTO `users`
            (`username`, `abbr`, `role`, `locale`)
            VALUES ("userdelete", "UD", "ROLE_DEV", "en")');
        $userId = $this->connection->lastInsertId();

        // Make delete request
        $this->client->request('POST', '/user/delete', ['id' => $userId]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['success' => true]);

        // Verify deletion in database
        $query = "SELECT COUNT(*) as count FROM `users` WHERE `id` = $userId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int)$result['count']);
    }

    public function testDeleteUserActionAsDev(): void
    {
        // Create a user to delete
        $this->connection->query('INSERT INTO `users`
            (`username`, `abbr`, `role`, `locale`)
            VALUES ("devuserdelete", "DD", "ROLE_DEV", "en")');
        $userId = $this->connection->lastInsertId();

        // Set user as developer
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_DEV" WHERE `id` = 1');

        // Make delete request
        $this->client->request('POST', '/user/delete', ['id' => $userId]);

        // Should fail for developers
        $this->assertStatusCode(401);

        // Verify user still exists
        $query = "SELECT COUNT(*) as count FROM `users` WHERE `id` = $userId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(1, (int)$result['count']);

        // Reset user role
        $this->connection->query('UPDATE `users` SET `role` = "ROLE_ADMIN" WHERE `id` = 1');
    }
}
