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

    public function testBulkentryAction()
    {
        $parameter = [
            'startdate' => '2024-01-01',    //opt
            'enddate' => '2024-01-10',  //opt
            'starttime' => '8:00:00',    //req
            'endtime' => '10:00:00',    //opt
            'preset' => 1,   //req
            'skipweekend' => '1',
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

        $expectedDbEntry = [
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
        //assert count of bulkentries
        $this->assertSame(8, count($results));

        // assert static key => values
        foreach ($results as $res) {
            $this->assertArraySubset($expectedDbEntry, $res);
        }

        // assert days
        $days = array_column($results, 'day');
        $expectedDays = [
            '2024-01-01',
            '2024-01-02',
            '2024-01-03',
            '2024-01-04',
            '2024-01-05',
            '2024-01-08',
            '2024-01-09',
            '2024-01-10',
        ];
        $this->assertSame($expectedDays, $days);
    }
}
