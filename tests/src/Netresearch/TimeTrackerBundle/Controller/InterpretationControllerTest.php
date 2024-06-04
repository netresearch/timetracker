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
            'user' => 3,    //req
        ];
        $expectedJson = array(
            0 => array(
                'id' => 1,
                'name' => 'Backen',
                'hours' => 1.1666666666666667,
                'quota' => '100.00%',
            ),
        );
        $this->client->request('GET', '/interpretation/activity', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetAllEntriesActionDevNotAllowed()
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/interpretation/allEntries', []);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testGetAllEntriesActionWrongParameterDateAsString()
    {
        $parameter = [
            'datestart=not a date',
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(406);
        $this->assertJsonStructure([
            'message' => 'DateTime::__construct(): Failed to parse time string (not a date) at position 0 (n): The timezone could not be found in the database',
        ]);
    }

    public function testGetAllEntriesActionWrongParameterDateAsInteger()
    {
        $parameter = [
            'dateend=1',
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(406);
        $this->assertJsonStructure([
            'message' => 'DateTime::__construct(): Failed to parse time string (1) at position 0 (1): Unexpected character',
        ]);
    }

    public function testGetAllEntriesActionReturnDataNoParameter()
    {
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?page=0',
            'last' => 'http://localhost/interpretation/allEntries?page=0',
            'prev' => null,
            'next' => null,
        ];
        $expectedData['data'] = [
            [
                'id' => 7,
                'date' => '0500-01-31',
                'start' => '14:00',
                'end' => '14:20',
                'description' => 'testGroupByActivityAction',
                'ticket' => 'testGroupByActivityAction',
                'duration' => 20,
                'durationString' => '00:20',
                'user_id' => 3,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
            [
                'id' => 6,
                'date' => '0500-01-30',
                'start' => '14:00',
                'end' => '14:50',
                'description' => 'testGroupByActivityAction',
                'ticket' => 'testGroupByActivityAction',
                'duration' => 50,
                'durationString' => '00:50',
                'user_id' => 3,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
            [
                'id' => 5,
                // 'date' => date('Y-m-d', strtotime('-3 days')),   //we dont test for dynamic date
                'start' => '14:00',
                'end' => '14:25',
                'description' => 'testGetDataAction',
                'ticket' => 'testGetDataAction',
                'duration' => 25,
                'durationString' => '00:25',
                'user_id' => 1,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
            [
                'id' => 4,
                // 'date' => date('Y-m-d'), //we dont test for dynamic date
                'start' => '13:00',
                'end' => '13:25',
                'description' => 'testGetDataAction',
                'ticket' => 'testGetDataAction',
                'duration' => 25,
                'durationString' => '00:25',
                'user_id' => 1,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
            [
                'id' => 3,
                'date' => '1000-01-29',
                'start' => '13:00',
                'end' => '13:14',
                'description' => '/interpretation/entries',
                'ticket' => 'testGroupByWorktimeAction',
                'duration' => 14,
                'durationString' => '00:14',
                'user_id' => 1,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
            [
                'id' => 2,
                'date' => '1000-01-30',
                'start' => '10:00',
                'end' => '12:50',
                'description' => '/interpretation/entries',
                'ticket' => 'testGetLastEntriesAction',
                'duration' => 170,
                'durationString' => '02:50',
                'user_id' => 1,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
            [
                'id' => 1,
                'date' => '1000-01-30',
                'start' => '08:00',
                'end' => '08:50',
                'description' => '/interpretation/entries',
                'ticket' => 'testGetLastEntriesAction',
                'duration' => 50,
                'durationString' => '00:50',
                'user_id' => 1,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
        ];
        $this->client->request('POST', '/interpretation/allEntries');
        $this->assertStatusCode(200);
        $this->assertLength(7, 'data');
        $this->assertJsonStructure($expectedLinks);
        $this->assertJsonStructure($expectedData);
    }

    public function testGetAllEntriesActionReturnDataWithParameter()
    {
        // test for parameter
        $parameter = [
            'datestart=500-04-29',
            'dateend=1500-04-29',
            'project_id=1',
            'customer_id=1',
            'activity_id=1',
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?activity_id=1&customer_id=1&dateend=1500-04-29&datestart=500-04-29&project_id=1&page=0',
            'last' => 'http://localhost/interpretation/allEntries?activity_id=1&customer_id=1&dateend=1500-04-29&datestart=500-04-29&project_id=1&page=0',
            'prev' => null,
            'next' => null,
        ];
        $expectedData['data'] = [
            [
                'id' => 3,
                'date' => '1000-01-29',
                'start' => '13:00',
                'end' => '13:14',
                'description' => '/interpretation/entries',
                'ticket' => 'testGroupByWorktimeAction',
                'duration' => 14,
                'durationString' => '00:14',
                'user_id' => 1,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
            [
                'id' => 2,
                'date' => '1000-01-30',
                'start' => '10:00',
                'end' => '12:50',
                'description' => '/interpretation/entries',
                'ticket' => 'testGetLastEntriesAction',
                'duration' => 170,
                'durationString' => '02:50',
                'user_id' => 1,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
            [
                'id' => 1,
                'date' => '1000-01-30',
                'start' => '08:00',
                'end' => '08:50',
                'description' => '/interpretation/entries',
                'ticket' => 'testGetLastEntriesAction',
                'duration' => 50,
                'durationString' => '00:50',
                'user_id' => 1,
                'project_id' => 1,
                'customer_id' => 1,
                'activity_id' => 1,
            ],
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertLength(3, 'data');
        $this->assertJsonStructure($expectedLinks);
        $this->assertJsonStructure($expectedData);
    }

    public function testGetAllEntriesActionReturnLinksNegativePage()
    {
        $parameter = [
            'page=-1',
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(400);
        $this->assertJsonStructure(['message' => 'page can not be negative.',]);
    }

    public function testGetAllEntriesActionReturnLinksNoParameter()
    {
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?page=0',
            'last' => 'http://localhost/interpretation/allEntries?page=0',
            'prev' => null,
            'next' => null,
        ];
        $this->client->request('POST', '/interpretation/allEntries');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedLinks);
        $this->assertLength(7, 'data');

    }

    public function testGetAllEntriesActionReturnLinksPageOne()
    {
        $parameter = [
            'maxResults=2',
            'page=0',
        ];
        $expectedData['data'] = [
            ['id' => 7,],
            ['id' => 6,],
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?maxResults=2&page=0',
            'last' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'prev' => null,
            'next' => 'http://localhost/interpretation/allEntries?maxResults=2&page=1',
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);
        $this->assertLength(2, 'data');
        $this->assertJsonStructure($expectedLinks);
        $this->assertJsonStructure($expectedData);
    }

    public function testGetAllEntriesActionReturnLinksPageTwo()
    {
        $parameter = [
            'maxResults=2',
            'page=1',
        ];
        $expectedData['data'] = [
            ['id' => 5,],
            ['id' => 4,],
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?maxResults=2&page=1',
            'last' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'prev' => 'http://localhost/interpretation/allEntries?maxResults=2&page=0',
            'next' => 'http://localhost/interpretation/allEntries?maxResults=2&page=2',
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);
        $this->assertLength(2, 'data');
        $this->assertJsonStructure($expectedLinks);
        $this->assertJsonStructure($expectedData);
    }

    public function testGetAllEntriesActionReturnLinksLastPage()
    {
        $parameter = [
            'maxResults=2',
            'page=3',
        ];
        $expectedData['data'] = [
            ['id' => 1,],
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'last' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'prev' => 'http://localhost/interpretation/allEntries?maxResults=2&page=2',
            'next' => null,
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);
        $this->assertLength(1, 'data');
        $this->assertJsonStructure($expectedLinks);
        $this->assertJsonStructure($expectedData);
    }

    public function testGetAllEntriesActionReturnLinksEmptyData()
    {
        $parameter = [
            'project_id=42',
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?project_id=42&page=0',
            'last' => null,
            'prev' => null,
            'next' => null,
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);
        $this->assertLength(0, 'data');
        $this->assertJsonStructure($expectedLinks);
    }

    public function testGetAllEntriesActionReturnLinksNonExistingPage()
    {
        $parameter = [
            'maxResults=2',
            'page=42',
        ];
        $expectedLinks['links'] = [
            'self' => 'http://localhost/interpretation/allEntries?maxResults=2&page=42',
            'last' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'prev' => 'http://localhost/interpretation/allEntries?maxResults=2&page=3',
            'next' => null,
        ];
        $this->client->request('POST', '/interpretation/allEntries?' . implode('&', $parameter));
        $this->assertStatusCode(200);
        $this->assertLength(0, 'data');
        $this->assertJsonStructure($expectedLinks);
    }
}
