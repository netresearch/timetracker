<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class CustomerControllerTest extends AbstractWebTestCase
{
    public function testGetCustomersAction(): void
    {
        // Test getting all customers
        $this->client->request('GET', '/getAllCustomers');
        $this->assertStatusCode(200);

        // Check structure of response
        $this->assertJsonStructure([
            'customer' => [
                'id' => 1,
                'name' => 'Test Customer',
                'active' => 1,
            ],
        ]);
    }

    public function testSaveCustomerAction(): void
    {
        // Prepare customer data for creation
        $customerData = [
            'id' => '',
            'name' => 'New Test Customer',
            'active' => 1,
            'global' => 0,
            'teams' => []
        ];

        // Make the request
        $this->client->request('POST', '/customer/save', $customerData);
        $this->assertStatusCode(200);

        // Check if the customer was saved correctly
        $query = 'SELECT * FROM `customers` WHERE `name` = "New Test Customer"';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $this->assertCount(1, $result);
        $this->assertEquals('New Test Customer', $result[0]['name']);
        $this->assertEquals(1, $result[0]['active']);
        $this->assertEquals(0, $result[0]['global']);
    }

    public function testUpdateCustomerAction(): void
    {
        // First, create a customer to update
        $this->connection->query('INSERT INTO `customers`
            (`name`, `active`, `global`)
            VALUES ("Customer to Update", 1, 0)');
        $customerId = $this->connection->lastInsertId();

        // Prepare update data
        $updateData = [
            'id' => $customerId,
            'name' => 'Updated Customer Name',
            'active' => 1,
            'global' => 1,
            'teams' => []
        ];

        // Make the update request
        $this->client->request('POST', '/customer/save', $updateData);
        $this->assertStatusCode(200);

        // Verify the update in the database
        $query = "SELECT * FROM `customers` WHERE `id` = $customerId";
        $result = $this->connection->query($query)->fetchAssociative();

        $this->assertEquals('Updated Customer Name', $result['name']);
        $this->assertEquals(1, $result['active']);
        $this->assertEquals(1, $result['global']);
    }

    public function testSaveCustomerActionAsDev(): void
    {
        // Set user as developer (not admin)
        $this->logInSession('unittest');

        $customerData = [
            'id' => '',
            'name' => 'Dev Customer',
            'active' => 1,
            'global' => 0,
            'teams' => []
        ];

        // Make the request
        $this->client->request('POST', '/customer/save', $customerData);

        // Developers should not be able to create customers
        $this->assertStatusCode(401);
    }

    public function testDeleteCustomerAction(): void
    {
        // Create a customer to delete
        $this->connection->query('INSERT INTO `customers`
            (`name`, `active`, `global`)
            VALUES ("Customer to Delete", 1, 0)');
        $customerId = $this->connection->lastInsertId();

        // Make delete request
        $this->client->request('POST', '/customer/delete', ['id' => $customerId]);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['success' => true]);

        // Verify deletion in database
        $query = "SELECT COUNT(*) as count FROM `customers` WHERE `id` = $customerId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int)$result['count']);
    }

    public function testDeleteCustomerActionAsDev(): void
    {
        // Create a customer to delete
        $this->connection->query('INSERT INTO `customers`
            (`name`, `active`, `global`)
            VALUES ("Dev Customer to Delete", 1, 0)');
        $customerId = $this->connection->lastInsertId();

        // Set user as developer
        $this->logInSession('unittest');

        // Make delete request
        $this->client->request('POST', '/customer/delete', ['id' => $customerId]);

        // Should fail for developers
        $this->assertStatusCode(401);

        // Verify customer still exists
        $query = "SELECT COUNT(*) as count FROM `customers` WHERE `id` = $customerId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(1, (int)$result['count']);
    }
}
