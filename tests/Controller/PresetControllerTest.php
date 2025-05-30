<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class PresetControllerTest extends AbstractWebTestCase
{
    public function testGetPresetsAction(): void
    {
        // Migrated from AdminControllerTest
        $this->client->request('GET', '/getPresets');
        $this->assertStatusCode(200);

        $expectedJson = [
            'presets' => [
                [
                    'id' => 1,
                    'name' => 'Test Preset',
                    'ticket_id' => 'TEST-001',
                    'activity_id' => 1,
                    'description' => 'Test preset description',
                ],
            ],
        ];
        $this->assertJsonStructure($expectedJson);
    }

    public function testSavePresetAction(): void
    {
        // Migrated from AdminControllerTest
        $parameter = [
            'id' => '',
            'name' => 'New Test Preset',
            'ticket_id' => 'TEST-002',
            'activity_id' => 1,
            'description' => 'A new preset for testing',
        ];

        $this->client->request('POST', '/preset/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Preset wurde gespeichert.']);

        // Verify in DB
        $query = 'SELECT * FROM `presets` WHERE `name` = "New Test Preset"';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $this->assertCount(1, $result);
        $this->assertEquals('New Test Preset', $result[0]['name']);
        $this->assertEquals('TEST-002', $result[0]['ticket_id']);
        $this->assertEquals(1, $result[0]['activity_id']);
        $this->assertEquals('A new preset for testing', $result[0]['description']);
    }

    public function testSavePresetActionDev(): void
    {
        // Migrated from AdminControllerTest
        // Set user as developer (not admin)
        $this->logInSession('unittest');

        $parameter = [
            'id' => '',
            'name' => 'Dev Preset',
            'ticket_id' => 'TEST-003',
            'activity_id' => 1,
            'description' => 'Preset from developer',
        ];

        $this->client->request('POST', '/preset/save', $parameter);
        // Unlike other entities, developers CAN create presets
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Preset wurde gespeichert.']);

        // Verify in DB - should exist as developers can create presets
        $query = 'SELECT * FROM `presets` WHERE `name` = "Dev Preset"';
        $result = $this->connection->query($query)->fetchAllAssociative();
        $this->assertCount(1, $result);
    }

    public function testUpdatePresetAction(): void
    {
        // Migrated from AdminControllerTest
        // Create a preset to update
        $this->connection->query('INSERT INTO `presets` (`name`, `ticket_id`, `activity_id`, `description`) VALUES ("Preset to Update", "TEST-004", 1, "Original description")');
        $presetId = $this->connection->lastInsertId();

        $parameter = [
            'id' => $presetId,
            'name' => 'Updated Preset',
            'ticket_id' => 'TEST-004',
            'activity_id' => 1,
            'description' => 'Updated description',
        ];

        $this->client->request('POST', '/preset/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Preset wurde gespeichert.']);

        // Verify in DB
        $query = "SELECT * FROM `presets` WHERE `id` = $presetId";
        $result = $this->connection->query($query)->fetchAssociative();

        $this->assertEquals('Updated Preset', $result['name']);
        $this->assertEquals('Updated description', $result['description']);
    }

    public function testUpdatePresetActionDev(): void
    {
        // Migrated from AdminControllerTest
        // Create a preset to update
        $this->connection->query('INSERT INTO `presets` (`name`, `ticket_id`, `activity_id`, `description`) VALUES ("Dev Preset to Update", "TEST-005", 1, "Original dev description")');
        $presetId = $this->connection->lastInsertId();

        // Set user as developer (not admin)
        $this->logInSession('unittest');

        $parameter = [
            'id' => $presetId,
            'name' => 'Dev Updated Preset',
            'ticket_id' => 'TEST-005',
            'activity_id' => 1,
            'description' => 'Dev updated description',
        ];

        $this->client->request('POST', '/preset/save', $parameter);
        // Unlike other entities, developers CAN update presets
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Preset wurde gespeichert.']);

        // Verify DB was updated
        $query = "SELECT * FROM `presets` WHERE `id` = $presetId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals('Dev Updated Preset', $result['name']);
    }

    public function testDeletePresetAction(): void
    {
        // Migrated from AdminControllerTest
        // Create a preset to delete
        $this->connection->query('INSERT INTO `presets` (`name`, `ticket_id`, `activity_id`, `description`) VALUES ("Preset to Delete", "TEST-006", 1, "Delete me")');
        $presetId = $this->connection->lastInsertId();

        $parameter = [
            'id' => $presetId,
        ];

        $this->client->request('POST', '/preset/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Preset wurde gelöscht.']);

        // Verify in DB
        $query = "SELECT COUNT(*) as count FROM `presets` WHERE `id` = $presetId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int) $result['count']);
    }

    public function testDeletePresetActionDev(): void
    {
        // Migrated from AdminControllerTest
        // Create a preset to delete
        $this->connection->query('INSERT INTO `presets` (`name`, `ticket_id`, `activity_id`, `description`) VALUES ("Dev Preset to Delete", "TEST-007", 1, "Dev delete me")');
        $presetId = $this->connection->lastInsertId();

        // Set user as developer (not admin)
        $this->logInSession('unittest');

        $parameter = [
            'id' => $presetId,
        ];

        $this->client->request('POST', '/preset/delete', $parameter);
        // Unlike other entities, developers CAN delete presets
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['message' => 'Preset wurde gelöscht.']);

        // Verify in DB that it was deleted
        $query = "SELECT COUNT(*) as count FROM `presets` WHERE `id` = $presetId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int) $result['count']);
    }
}
