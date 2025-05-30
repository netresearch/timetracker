<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class TicketSystemControllerTest extends AbstractWebTestCase
{
    public function testGetTicketSystemsAction(): void
    {
        // Create a test ticket system first
        $this->connection->query('INSERT INTO `ticket_systems`
            (`name`, `url`, `type`, `login`, `password`, `ticketurl`, `public_key`, `private_key`)
            VALUES ("Test Ticket System API", "http://test-api.example.com", "JIRA", "testuser", "testpassword", "http://test-api.example.com/ticket/{id}", "", "")');

        // Migrated from AdminControllerTest
        $this->client->request('GET', '/ticketsystems');
        $this->assertStatusCode(200);

        // Check we get a valid JSON response
        $content = $this->client->getResponse()->getContent();
        $this->assertJson($content);

        // Parse response and validate structure
        $data = json_decode($content, true);
        $this->assertIsArray($data);

        // Find our test system in the results
        $found = false;
        foreach ($data as $system) {
            if (isset($system['ticketSystem']) &&
                isset($system['ticketSystem']['name']) &&
                $system['ticketSystem']['name'] === 'Test Ticket System API') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'The created test ticket system should be found in the API response');
    }

    public function testSaveTicketSystemAction(): void
    {
        // Migrated from AdminControllerTest
        $parameter = [
            'id' => '',
            'name' => 'Test Ticket System',
            'url' => 'http://test-ticketsystem.example.com',
            'type' => 'JIRA',
            'login' => 'testuser',
            'password' => 'testpassword',
            'ticketUrl' => 'http://test-ticketsystem.example.com/ticket/%s',
            'publicKey' => '',
            'privateKey' => ''
        ];

        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(200);

        // Verify JSON response
        $content = $this->client->getResponse()->getContent();
        $this->assertJson($content);

        // Check database for the newly created ticket system
        $result = $this->connection->query(
            "SELECT * FROM `ticket_systems` WHERE `name` = 'Test Ticket System'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($result, 'Ticket system should exist in database');
        $this->assertEquals('http://test-ticketsystem.example.com', $result['url']);
        $this->assertEquals('JIRA', $result['type']);
        $this->assertEquals('testuser', $result['login']);
    }

    public function testSaveTicketSystemActionAsDevDenied(): void
    {
        // Set user as developer (not admin)
        $this->logInSession('developer');

        $parameter = [
            'id' => '',
            'name' => 'Dev Test Ticket System',
            'url' => 'http://dev-test-ticketsystem.example.com',
            'type' => 'JIRA',
            'login' => 'devuser',
            'password' => 'devpassword',
            'ticketUrl' => 'http://dev-test-ticketsystem.example.com/ticket/{id}',
            'publicKey' => '',
            'privateKey' => ''
        ];

        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(403); // Should return forbidden for dev users

        // Verify in DB - should not exist
        $result = $this->connection->query(
            "SELECT COUNT(*) as count FROM `ticket_systems` WHERE `name` = 'Dev Test Ticket System'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(0, (int)$result['count'], 'Ticket system should not be created by a developer role');
    }

    public function testUpdateTicketSystemAction(): void
    {
        // Create a ticket system to update
        $this->connection->query('INSERT INTO `ticket_systems`
            (`name`, `url`, `type`, `login`, `password`, `ticketurl`, `public_key`, `private_key`)
            VALUES ("Update Test System", "http://update-test.example.com", "JIRA", "updateuser", "updatepassword", "http://update-test.example.com/ticket/{id}", "", "")');
        $ticketSystemId = $this->connection->lastInsertId();

        $parameter = [
            'id' => $ticketSystemId,
            'name' => 'Updated System Name',
            'url' => 'http://update-test.example.com',
            'type' => 'JIRA',
            'login' => 'updateduser',
            'password' => 'updatedpassword',
            'ticketUrl' => 'http://update-test.example.com/ticket/{id}',
            'publicKey' => '',
            'privateKey' => ''
        ];

        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(200);

        // Verify updated record in database
        $result = $this->connection->query(
            "SELECT * FROM `ticket_systems` WHERE `id` = $ticketSystemId"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($result, 'Ticket system should exist in database');
        $this->assertEquals('Updated System Name', $result['name']);
        $this->assertEquals('updateduser', $result['login']);
        $this->assertEquals('updatedpassword', $result['password']);
    }

    public function testUpdateTicketSystemActionAsDevDenied(): void
    {
        // Create a ticket system to update
        $this->connection->query('INSERT INTO `ticket_systems`
            (`name`, `url`, `type`, `login`, `password`, `ticketurl`, `public_key`, `private_key`)
            VALUES ("Dev Update Test System", "http://dev-update-test.example.com", "OTRS", "devupdateuser", "devupdatepassword", "http://dev-update-test.example.com/ticket/{id}", "", "")');
        $ticketSystemId = $this->connection->lastInsertId();

        // Save original values for comparison later
        $originalSystem = $this->connection->query(
            "SELECT * FROM `ticket_systems` WHERE `id` = $ticketSystemId"
        )->fetch(\PDO::FETCH_ASSOC);

        // Set user as developer (not admin)
        $this->logInSession('developer');

        $parameter = [
            'id' => $ticketSystemId,
            'name' => 'Dev Updated System Name',
            'url' => 'http://dev-update-test.example.com',
            'type' => 'OTRS',
            'login' => 'devupdateduser',
            'password' => 'devupdatedpassword',
            'ticketUrl' => 'http://dev-update-test.example.com/ticket/{id}',
            'publicKey' => '',
            'privateKey' => ''
        ];

        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(403); // Should return forbidden for dev users

        // Verify the record was not modified
        $updatedSystem = $this->connection->query(
            "SELECT * FROM `ticket_systems` WHERE `id` = $ticketSystemId"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals($originalSystem['name'], $updatedSystem['name'], 'Name should not be changed by developer');
        $this->assertEquals($originalSystem['login'], $updatedSystem['login'], 'Login should not be changed by developer');
    }

    public function testDeleteTicketSystemAction(): void
    {
        // Create a ticket system to delete
        $this->connection->query('INSERT INTO `ticket_systems`
            (`name`, `url`, `type`, `login`, `password`, `ticketurl`, `public_key`, `private_key`)
            VALUES ("Delete Test System", "http://delete-test.example.com", "JIRA", "deleteuser", "deletepassword", "http://delete-test.example.com/ticket/{id}", "", "")');
        $ticketSystemId = $this->connection->lastInsertId();

        $parameter = [
            'id' => $ticketSystemId,
        ];

        $this->client->request('POST', '/ticketsystem/delete', $parameter);
        $this->assertStatusCode(200);

        // Verify the system was deleted from the database
        $result = $this->connection->query(
            "SELECT COUNT(*) as count FROM `ticket_systems` WHERE `id` = $ticketSystemId"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(0, (int)$result['count'], 'Ticket system should be deleted from database');
    }

    public function testDeleteTicketSystemActionAsDevDenied(): void
    {
        // Create a ticket system to delete
        $this->connection->query('INSERT INTO `ticket_systems`
            (`name`, `url`, `type`, `login`, `password`, `ticketurl`, `public_key`, `private_key`)
            VALUES ("Dev Delete Test System", "http://dev-delete-test.example.com", "JIRA", "devdeleteuser", "devdeletepassword", "http://dev-delete-test.example.com/ticket/{id}", "", "")');
        $ticketSystemId = $this->connection->lastInsertId();

        // Set user as developer (not admin)
        $this->logInSession('developer');

        $parameter = [
            'id' => $ticketSystemId,
        ];

        $this->client->request('POST', '/ticketsystem/delete', $parameter);
        $this->assertStatusCode(403); // Should return forbidden for dev users

        // Verify the system was not deleted from the database
        $result = $this->connection->query(
            "SELECT COUNT(*) as count FROM `ticket_systems` WHERE `id` = $ticketSystemId"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(1, (int)$result['count'], 'Ticket system should not be deleted by developer');
    }
}
