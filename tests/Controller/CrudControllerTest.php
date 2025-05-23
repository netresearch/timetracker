<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class CrudControllerTest extends AbstractWebTestCase
{
    public function testSaveAction(): void
    {
        $parameter = [
            'start' => '09:25:00',
            'project' => 1, //req
            'customer' => 1,    //req->must be given, but can be ''
            'activity' => 1,    //req->-||-
            'end' => '09:55:00',
            'date' => '2024-01-01',
        ];

        $this->client->request('POST', '/tracking/save', $parameter);

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
        // Create a test entry first
        $parameter = [
            'start' => '09:25:00',
            'project' => 1,
            'customer' => 1,
            'activity' => 1,
            'end' => '09:55:00',
            'date' => '2024-01-01',
        ];

        $this->client->request('POST', '/tracking/save', $parameter);
        $this->assertStatusCode(200);

        // Get the created entry ID
        $query = 'SELECT id FROM `entries` WHERE `day` = "2024-01-01" ORDER BY `id` DESC LIMIT 1';
        $result = $this->connection->query($query)->fetchAssociative();
        $entryId = (int) $result['id'];

        // Now perform the delete
        $deleteParam = ['id' => $entryId];
        $this->client->request('POST', '/tracking/delete', $deleteParam);
        $this->assertStatusCode(200);
        $this->assertJsonStructure(['success' => true, 'alert' => null]);

        // Verify entry is deleted
        $query = "SELECT COUNT(*) as count FROM `entries` WHERE `id` = $entryId";
        $result = $this->connection->query($query)->fetchAssociative();
        $this->assertEquals(0, (int) $result['count'], "Entry was not deleted from database");

        // Try to delete again and expect 404
        $this->client->request('POST', '/tracking/delete', $deleteParam);
        $this->assertStatusCode(404);
        $this->assertJsonStructure(['message' => 'No entry for id.']);
    }

    //-------------- Bulkentry routes ----------------------------------------

    public function testBulkentryActionNonExistendPreset(): void
    {
        $parameter = [
            'preset' => 42,   //req
        ];
        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Preset not found');
    }

    public function testBulkentryActionZeroTimeDiff(): void
    {
        // test duration = 0
        $parameter = [
            'preset' => 1,   //req
            'starttime' => '08:00:00',    //req
            'endtime' => '08:00:00',    //opt
        ];
        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Die Aktivität muss mindestens eine Minute angedauert haben!');
    }

    public function testBulkentryAction(): void
    {
        $parameter = [
            'startdate' => '2020-01-25',    //opt
            'enddate' => '2020-02-06',  //opt
            'preset' => 1,   //req
            'usecontract' => 1,   //opt
        ];

        $this->client->request('POST', '/tracking/bulkentry', $parameter);
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

    public function testBulkentryActionCustomTime(): void
    {
        $parameter = [
            'startdate' => '2024-01-01',    //opt
            'enddate' => '2024-01-10',  //opt
            'starttime' => '08:00:00',    //req
            'endtime' => '10:00:00',    //opt
            'preset' => 1,   //req
            'skipweekend' => 1,   //opt
        ];

        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(200);
        $this->assertMessage('8 Einträge wurden angelegt.');

        $query = 'SELECT *
            FROM `entries`
            WHERE `day` >= "2024-01-01"
            AND `day` <= "2024-01-10"
            ORDER BY `id` ASC';
        $results = $this->connection->query($query)->fetchAllAssociative();
        $this->assertSame(8, count($results));

        // Assert days for the expected entries
        $staticExpected = [
            'start' => '08:00:00',
            'end' => '10:00:00',
            'customer_id' => '1',
            'project_id' => '1',
            'activity_id' => '1',
            'description' => 'Urlaub',
            'duration' => '120',
            'user_id' => '1',
            'class' => '2',
        ];

        // We'll just validate the first 8 entries since that's what's in the test
        $variableExpected = [
            ['day' => '2024-01-01',],
            ['day' => '2024-01-02',],
            ['day' => '2024-01-03',],
            ['day' => '2024-01-04',],
            ['day' => '2024-01-05',],
            ['day' => '2024-01-08',],
            ['day' => '2024-01-09',],
            ['day' => '2024-01-10',],
        ];
        $counter = count($results);

        for ($i = 0; $i < $counter; $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testBulkentryActionSkipWeekend(): void
    {
        $parameter = [
            'startdate' => '2020-02-07',    //opt
            'enddate' => '2020-02-10',  //opt
            'preset' => 1,   //req
            'usecontract' => 1,   //opt
            'skipweekend' => 1,   //opt
        ];

        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(200);
        $this->assertMessage('2 Einträge wurden angelegt.');

        $query = 'SELECT *
            FROM `entries`
            WHERE `day` >= "2020-02-07"
            AND `day` <= "2020-02-10"
            ORDER BY `id` ASC';
        $results = $this->connection->query($query)->fetchAllAssociative();
        $this->assertSame(2, count($results));

        $staticExpected =  [
            'start' => '08:00:00',
            'customer_id' => '1',
            'project_id' => '1',
            'activity_id' => '1',
            'description' => 'Urlaub',
            'user_id' => '1',
            'class' => '2',
        ];

        $variableExpected = [
            ['day' => '2020-02-07', 'end' => '13:30:00', 'duration' => '330'],
            ['day' => '2020-02-10', 'end' => '09:06:00', 'duration' => '66'],
        ];
        $counter = count($results);

        for ($i = 0; $i < $counter; $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testBulkentryActionNoContractInDatabase(): void
    {
        // login as user without contract
        $this->logInSession('noContract');

        $parameter = [
            'startdate' => '2020-01-25',    //opt
            'enddate' => '2020-02-06',  //opt
            'preset' => 1,   //req
            'usecontract' => 1,   //opt
        ];

        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Für den Benutzer wurde kein Vertrag gefunden. Bitte verwenden Sie eine benutzerdefinierte Zeit.');
    }

    public function testBulkentryActionContractEnddateIsNull(): void
    {
        $parameter = [
            'startdate' => '2020-02-10',    //opt
            'enddate' => '2020-02-20',  //opt
            'preset' => 1,   //req
            'usecontract' => 1,   //opt
        ];

        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(200);
        $this->assertMessage('10 Einträge wurden angelegt.');

        $query = 'SELECT *
            FROM `entries`
            WHERE `day` >= "2020-02-10"
            AND `day` <= "2020-02-20"
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

        // We only check the first 10 expected entries as that was the original test
        $variableExpected = [
            ['day' => '2020-02-10', 'end' => '09:06:00', 'duration' => '66'],
            ['day' => '2020-02-11', 'end' => '10:12:00', 'duration' => '132'],
            ['day' => '2020-02-12', 'end' => '11:18:00', 'duration' => '198'],
            ['day' => '2020-02-13', 'end' => '12:24:00', 'duration' => '264'],
            ['day' => '2020-02-14', 'end' => '13:30:00', 'duration' => '330'],
            ['day' => '2020-02-15', 'end' => '08:30:00', 'duration' => '30'],
            ['day' => '2020-02-17', 'end' => '09:06:00', 'duration' => '66'],
            ['day' => '2020-02-18', 'end' => '10:12:00', 'duration' => '132'],
            ['day' => '2020-02-19', 'end' => '11:18:00', 'duration' => '198'],
            ['day' => '2020-02-20', 'end' => '12:24:00', 'duration' => '264'],
        ];
        $counter = count($results);

        for ($i = 0; $i < $counter; $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testBulkentryActionContractEnded(): void
    {
        $this->logInSession('developer');

        $parameter = [
            'startdate' => '0020-02-10',    //opt
            'enddate' => '0020-02-20',  //opt
            'preset' => 1,   //req
            'usecontract' => 1,   //opt
        ];

        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(200);
        $this->assertMessage('0 Einträge wurden angelegt.<br/>Vertrag ist gültig ab 01.01.1020.');

        $query = 'SELECT *
            FROM `entries`
            WHERE `day` >= "0020-02-10"
            AND `day` <= "0020-02-20"
            ORDER BY `id` ASC';
        $results = $this->connection->query($query)->fetchAllAssociative();

        $this->assertSame(0, count($results));
    }

    public function testBulkentryActionContractEndedDuringBulkentry(): void
    {
        $this->logInSession('developer');

        $parameter = [
            'startdate' => '2019-12-29',    //opt
            'enddate' => '2020-01-05',  //opt
            'preset' => 1,   //req
            'usecontract' => 1,   //opt
        ];

        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(200);
        $this->assertMessage('4 Einträge wurden angelegt.<br/>Vertrag ist am 01.01.2020. abgelaufen.');

        $query = 'SELECT *
            FROM `entries`
            WHERE `day` >= "2019-12-29"
            AND `day` <= "2020-01-05"
            ORDER BY `id` ASC';
        $results = $this->connection->query($query)->fetchAllAssociative();

        $this->assertSame(4, count($results));

        $staticExpected = [
            'start' => '08:00:00',
            'customer_id' => '1',
            'project_id' => '1',
            'activity_id' => '1',
            'description' => 'Urlaub',
            'user_id' => '2',
            'class' => '2',
            'end' => '09:00:00',
            'duration' => '60'
        ];

        $variableExpected = [
            ['day' => '2019-12-29'],
            ['day' => '2019-12-30'],
            ['day' => '2019-12-31'],
            ['day' => '2020-01-01'],
        ];
        $counter = count($results);

        for ($i = 0; $i < $counter; $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testSaveActionWithTicket(): void
    {
        $parameter = [
            'start' => '08:00:00',
            'project' => 1,  // req
            'customer' => 1, // req->must be given, but can be ''
            'activity' => 1, // req->-||-
            'end' => '09:00:00',
            'date' => '2024-01-15',
            'ticket' => 'SA-123', // Using a ticket that matches the project's Jira ID
            'description' => 'Test ticket entry'
        ];

        $this->client->request('POST', '/tracking/save', $parameter);

        $expectedJson = [
            'result' => [
                'date' => '15/01/2024',
                'start' => '08:00',
                'end' => '09:00',
                'user' => 1,
                'customer' => 1,
                'project' => 1,
                'activity' => 1,
                'duration' => 60,
                'durationString' => '01:00',
                'class' => 2,
                'ticket' => 'SA-123',
                'description' => 'Test ticket entry'
            ],
        ];

        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        // Verify the database entry
        $query = 'SELECT * FROM `entries` WHERE `day` = "2024-01-15" ORDER BY `id` DESC LIMIT 1';
        $result = $this->connection->query($query)->fetchAllAssociative();

        $expectedDbEntry = [
            [
                'day' => '2024-01-15',
                'start' => '08:00:00',
                'end' => '09:00:00',
                'customer_id' => '1',
                'project_id' => '1',
                'activity_id' => '1',
                'duration' => '60',
                'user_id' => '1',
                'class' => '2',
                'ticket' => 'SA-123',
                'description' => 'Test ticket entry'
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }
}
