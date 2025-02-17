<?php

namespace Tests\Netresearch\TimeTrackerBundle\Controller;

use Tests\BaseTest;
use Illuminate\Support\Facades\DB;

class CrudControllerTest extends BaseTest
{
    public function testSaveAction()
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
        $result = $this->connection->query($query)->fetch_all(MYSQLI_ASSOC);

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

    public function testDeleteAction()
    {
        $parameter = ['id' => 1,];

        $this->client->request('POST', '/tracking/delete', $parameter);
        $this->assertStatusCode(200, 'First delete did not return expected 200');
        $this->assertJsonStructure(['success' => true, 'alert' => null]);
        //  second delete
        $this->client->request('POST', '/tracking/delete', $parameter);
        $this->assertStatusCode(404, 'Second delete did not return expected 404');
        $this->assertJsonStructure(['message' => 'No entry for id.']);
    }

    //-------------- Bulkentry routes ----------------------------------------

    public function testBulkentryActionNonExistendPreset()
    {
        $parameter = [
            'preset' => 42,   //req
        ];
        $this->client->request('POST', '/tracking/bulkentry', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Preset not found');
    }

    public function testBulkentryActionZeroTimeDiff()
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

    public function testBulkentryAction()
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
        $results = $this->connection->query($query)->fetch_all(MYSQLI_ASSOC);

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

        for ($i = 0; $i < count($results); $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testBulkentryActionCustomTime()
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
        $results = $this->connection->query($query)->fetch_all(MYSQLI_ASSOC);
        $this->assertSame(8, count($results));

        // assert days
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

        for ($i = 0; $i < count($results); $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testBulkentryActionSkipWeekend()
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
        $results = $this->connection->query($query)->fetch_all(MYSQLI_ASSOC);
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

        for ($i = 0; $i < count($results); $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testBulkentryActionNoContractInDatabase()
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

    public function testBulkentryActionContractEnddateIsNull()
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
        $results = $this->connection->query($query)->fetch_all(MYSQLI_ASSOC);

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

        for ($i = 0; $i < count($results); $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }

    public function testBulkentryActionContractEnded()
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
        $results = $this->connection->query($query)->fetch_all(MYSQLI_ASSOC);

        $this->assertSame(0, count($results));
    }

    public function testBulkentryActionContractEndedDuringBulkentry()
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
        $results = $this->connection->query($query)->fetch_all(MYSQLI_ASSOC);

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

        for ($i = 0; $i < count($results); $i++) {
            $this->assertArraySubset($staticExpected, $results[$i]);
            $this->assertArraySubset($variableExpected[$i], $results[$i]);
        }
    }
}
