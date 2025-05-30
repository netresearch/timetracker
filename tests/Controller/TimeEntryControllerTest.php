<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class TimeEntryControllerTest extends AbstractWebTestCase
{
    public function testSaveAction(): void
    {
        // This test is migrated from CrudControllerTest::testSaveAction
        $parameter = [
            'start' => '09:25:00',
            'project' => 1, //req
            'customer' => 1,    //req->must be given, but can be ''
            'activity' => 1,    //req->-||-
            'end' => '09:55:00',
            'date' => '2024-01-01',
        ];

        // Make the request to the new endpoint
        $this->client->request('POST', '/crud/save', $parameter);

        $expectedJson = [
            'result' => [
                'date' => '01/01/2024',
                'start' => '09:25',
                'end' => '09:55',
                'user' => 1,
                'customer' => 1,
                'project' => 1,
                'activity' => 1,
                'duration' => 30,
                'durationString' => '00:30',
                'class' => 2,
            ],
        ];

        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        $query = 'SELECT * FROM `entries` ORDER BY `id` DESC LIMIT 1';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $expectedDbEntry = [
            [
                'day' => '2024-01-01',
                'start' => '09:25:00',
                'end' => '09:55:00',
                'customer_id' => '1',
                'project_id' => '1',
                'activity_id' => '1',
                'duration' => '30',
                'user_id' => '1',
                'class' => '2',
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveAndDeleteWorkLog(): void
    {
        // This test is migrated from CrudControllerTest::testSaveAndDeleteWorkLog
        // Create a test entry first
        $parameter = [
            'start' => '09:25:00',
            'project' => 1,
            'customer' => 1,
            'activity' => 1,
            'end' => '09:55:00',
            'date' => '2024-01-01',
        ];

        $this->client->request('POST', '/crud/save', $parameter);
        $this->assertStatusCode(200);

        // Get the created entry ID
        $query = 'SELECT id FROM `entries` WHERE `day` = "2024-01-01" ORDER BY `id` DESC LIMIT 1';
        $result = $this->connection->query($query)->fetchAssociative();
        $entryId = (int) $result['id'];

        // Now perform the delete
        $deleteParam = ['id' => $entryId];
        $this->client->request('POST', '/crud/delete', $deleteParam);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['success' => true, 'alert' => null]);

        // Verify entry is deleted
        $query = "SELECT COUNT(*) as count FROM `entries` WHERE `id` = $entryId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int) $result['count'], "Entry was not deleted from database");

        // Try to delete again and expect 404
        $this->client->request('POST', '/crud/delete', $deleteParam);
        $this->assertStatusCode(404);
        $this->assertJsonStructure(['message' => 'No entry for id.']);
    }

    public function testBulkentryActionNonExistendPreset(): void
    {
        // This test is migrated from CrudControllerTest::testBulkentryActionNonExistendPreset
        $parameter = [
            'preset' => 42,   //req
        ];
        $this->client->request('POST', '/crud/bulkentry', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Preset not found');
    }

    public function testBulkentryActionZeroTimeDiff(): void
    {
        // This test is migrated from CrudControllerTest::testBulkentryActionZeroTimeDiff
        // test duration = 0
        $parameter = [
            'preset' => 1,   //req
            'starttime' => '08:00:00',    //req
            'endtime' => '08:00:00',    //opt
        ];
        $this->client->request('POST', '/crud/bulkentry', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Die Aktivität muss mindestens eine Minute angedauert haben!');
    }

    public function testBulkentryAction(): void
    {
        // This test is migrated from CrudControllerTest::testBulkentryAction
        $parameter = [
            'startdate' => '2020-01-25',    //opt
            'enddate' => '2020-02-06',  //opt
            'preset' => 1,   //req
            'usecontract' => 1,   //opt
        ];

        $this->client->request('POST', '/crud/bulkentry', $parameter);
        $this->assertStatusCode(200);
        $this->assertMessage('10 Einträge wurden angelegt.');

        $query = 'SELECT *
            FROM `entries`
            WHERE `day` >= "2020-01-25"
            AND `day` <= "2020-02-06"
            ORDER BY `id` ASC';
        $results = $this->connection->query($query)->fetchAllAssociative();

        $this->assertSame(10, count($results));

        $staticExpected = [
            'start' => '08:00:00',
            'customer_id' => '1',
            'project_id' => '1',
            'activity_id' => '1',
            'description' => 'Urlaub',
            'user_id' => '1',
            'class' => '2',
        ];

        $variableExpected = [
            ['day' => '2020-01-27', 'end' => '09:00:00', 'duration' => '60'],
            ['day' => '2020-01-28', 'end' => '10:00:00', 'duration' => '120'],
            ['day' => '2020-01-29', 'end' => '11:00:00', 'duration' => '180'],
            ['day' => '2020-01-30', 'end' => '12:00:00', 'duration' => '240'],
            ['day' => '2020-01-31', 'end' => '13:00:00', 'duration' => '300'],
            ['day' => '2020-02-01', 'end' => '08:30:00', 'duration' => '30'],
            ['day' => '2020-02-03', 'end' => '09:06:00', 'duration' => '66'],
            ['day' => '2020-02-04', 'end' => '10:12:00', 'duration' => '132'],
            ['day' => '2020-02-05', 'end' => '11:18:00', 'duration' => '198'],
            ['day' => '2020-02-06', 'end' => '12:24:00', 'duration' => '264'],
        ];
        $counter = count($results);

        for ($i = 0; $i < $counter; $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testSaveActionWithTicket(): void
    {
        // This test is migrated from CrudControllerTest::testSaveActionWithTicket
        $parameter = [
            'start' => '09:25:00',
            'project' => 1,
            'customer' => 1,
            'activity' => 1,
            'end' => '09:55:00',
            'date' => '2024-01-01',
            'ticket' => 'TIM-123',
        ];

        $this->client->request('POST', '/crud/save', $parameter);

        $expectedJson = [
            'result' => [
                'date' => '01/01/2024',
                'start' => '09:25',
                'end' => '09:55',
                'user' => 1,
                'customer' => 1,
                'project' => 1,
                'activity' => 1,
                'duration' => 30,
                'durationString' => '00:30',
                'class' => 2,
                'ticket' => 'TIM-123',
            ],
        ];

        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        $query = 'SELECT * FROM `entries` ORDER BY `id` DESC LIMIT 1';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $expectedDbEntry = [
            [
                'day' => '2024-01-01',
                'start' => '09:25:00',
                'end' => '09:55:00',
                'customer_id' => '1',
                'project_id' => '1',
                'activity_id' => '1',
                'duration' => '30',
                'user_id' => '1',
                'class' => '2',
                'ticket' => 'TIM-123',
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }
}
