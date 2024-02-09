<?php

use Tests\BaseTest;

class InterpretationControllerTest extends BaseTest
{
    public function testGetLastEntriesAction()
    {
        $parameter = [
            'user' => 1,    //req
            'ticket' => 'testGetLastEntriesAction',    //req
        ];

        $expectedJson = [
            [
                'entry' => [
                    'date' => '30/01/1000',
                    'start' => '10:00',
                    'end' => '12:50',
                    'user' => 1,
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => '/interpretation/entries',
                    'ticket' => 'testGetLastEntriesAction',
                    'duration' => '02:50',
                    'durationString' => '02:50',
                    'class' => 1,
                    'quota' => '77.27%',
                ],
            ],
            [
                'entry' => [
                    'date' => '30/01/1000',
                    'start' => '08:00',
                    'end' => '08:50',
                    'user' => 1,
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => '/interpretation/entries',
                    'ticket' => 'testGetLastEntriesAction',
                    'duration' => '00:50',
                    'durationString' => '00:50',
                    'class' => 1,
                    'quota' => '22.73%',
                ],
            ],
        ];

        $this->client->request('GET', '/interpretation/entries', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGroupByWorktimeAction()
    {
        $parameter = [
            'user' => 1,    //req
            'datestart' => '1000-01-29',    //opt
            'dateend' => '1000-01-30',  //opt
        ];

        $expectedJson = [
            [
                'name' => '00-01-29',
                'day' => '29.01.',
                'hours' => 0.23333333333333334,
                'quota' => '5.98%',
            ],
            [
                'name' => '00-01-30',
                'day' => '30.01.',
                'hours' => 3.6666666666666665,
                'quota' => '94.02%',
            ],
        ];

        $this->client->request('GET', '/interpretation/time', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGroupByActivityAction()
    {
        $parameter = [
            'user' => 1,    //req
        ];
        $expectedJson = array(
            0 => array(
                'id' => 1,
                'name' => 'Backen',
                'hours' => 3.9,
                'quota' => '100.00%',
            ),
        );
        $this->client->request('GET', '/interpretation/activity', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }
}
