<?php

namespace Tests\Netresearch\TimeTrackerBundle\Controller;

use Tests\BaseTest;

class AdminControllerTest extends BaseTest
{
    public function testUserSaveUserExists()
    {
        $parameter = [
            'username' => 'unittest',
            'abbr'     => 'IMY',
            //FIXME: 500 when non-existing abb is used
            'teams'    => [1], //req
            'locale'   => 'en',   //req
        ];
        $this->client->request('POST', '/user/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('The user name abreviation provided already exists.');
    }

    public function testGetTeamsAction()
    {
        $expectedJson = [
            [
                'team' => [
                    'name' => 'Hackerman',
                    'lead_user_id' => 1,
                ],
            ],
            [
                'team' => [
                    'name' => 'Kuchenbäcker',
                    'lead_user_id' => 1,
                ],
            ],
        ];

        $this->client->request('GET', '/getAllTeams');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }
    public function testDeleteTeamAction()
    {
        $parameter = ['id' => 1,];

        //first delete
        $expectedJson = [
            'success' => true,
        ];
        $this->client->request('POST', '/team/delete', $parameter);
        $this->assertStatusCode(200, 'First delete did not return expected 200');
        $this->assertJsonStructure($expectedJson);

        //  second delete
        $expectedJson2 = [
            'message' => 'Dataset could not be removed. ',
        ];
        $this->client->request('POST', '/team/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);

        //test that dev cant delete team
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/team/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testSaveTeamAction()
    {
        $parameter = [
            'name' => 'testSaveTeamAction', //opt
            'lead_user_id' => 1, //req
        ];

        $this->client->request('POST', '/team/save', $parameter);

        $expectedJson = [
            1 => 'testSaveTeamAction',
            2 => 1,
        ];

        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        $this->queryBuilder->select('name', 'lead_user_id')
            ->from('teams')->where('name = ?')
            ->setParameter(0, 'testSaveTeamAction');
        $result = $this->queryBuilder->execute()->fetchAll();

        $expectedDBentry = [
            [
                'name' => 'testSaveTeamAction',
                'lead_user_id' => 1,
            ]
        ];
        $this->assertArraySubset($expectedDBentry, $result);

        //test that dev cant save team
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testSaveTeamActionFromNotPL', //opt
            'lead_user_id' => 1, //req
        ];
        $this->client->request('POST', '/team/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test for updating a existing team
     * Uses the same route and method as SaveTeamAction
     * When sending a existing id, the team with that id gets updated
     *
     * @return void
     */
    public function testUpdateTeam()
    {
        $parameter = [
            'lead_user_id' => 2, //req
            'name' => 'updatedKuchenbäcker', //opt
            'id' => 1,  //for update req
        ];
        $this->client->request('POST', '/team/save', $parameter);
        $expectedJson = [
            0 => 1,
            1 => 'updatedKuchenbäcker',
            2 => 2,
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        //validate updated entry in db
        $this->queryBuilder->select('name', 'lead_user_id')
            ->from('teams')->where('id = ?')
            ->setParameter(0, 1);
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDBentry = [
            [
                'name' => 'updatedKuchenbäcker',
                'lead_user_id' => 2,
            ]
        ];
        $this->assertArraySubset($expectedDBentry, $result);
    }

    public function testUpdateTeamPermission()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('teams')
            ->execute()
            ->fetchAll();

        //test that dev cant update team
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'testSaveTeamActionFromNotPL', //opt
            'lead_user_id' => 1, //req
        ];
        $this->client->request('POST', '/team/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');

        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('teams')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }
}
