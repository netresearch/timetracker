<?php

namespace Tests\Netresearch\TimeTrackerBundle\Controller;

use Tests\BaseTest;

class AdminControllerTest extends BaseTest
{
    //-------------- users routes ----------------------------------------
    public function testSaveUserAction()
    {
        $parameter = [
            'username' => 'unittest',
            'abbr' => 'WAS',
            'teams' => ['2'], //req
            'locale' => 'en',   //req
            'type' => 'PL'    //req
        ];
        $expectedJson = [
            1 => 'unittest',
            2 => 'WAS',
            3 => 'PL',
        ];
        $this->client->request('POST', '/user/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testSaveUserActionDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('users')
            ->execute()
            ->fetchAll();
        //test that dev cant save users
        $this->logInSession('developer');
        $parameter = [
            'username' => 'unittest',
            'abbr' => 'IMY',
            'teams' => [1], //req
            'locale' => 'en',   //req
        ];
        $this->client->request('POST', '/user/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('users')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testUpdateUser()
    {
        $parameter = [
            'id' => 1,
            'username' => 'unittestUpdate',
            'abbr' => 'WAR',
            'teams' => ['1'], //req
            'locale' => 'de',   //req
            'type' => 'DEV'    //req
        ];
        $this->client->request('POST', '/user/save', $parameter);

        $expectedJson = array(
            1 => 'unittestUpdate',
            2 => 'WAR',
            3 => 'DEV',
        );
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        // validate updated entry in db
        $this->queryBuilder->select('*')
            ->from('users')->where('id = ?')
            ->setParameter(0, 1);
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDBentry = array(
            0 => array(
                'id' => 1,
                'username' => 'unittestUpdate',
                'abbr' => 'WAR',
                'type' => 'DEV',
                'jira_token' => null,
                'locale' => 'de',
            ),
        );
        $this->assertArraySubset($expectedDBentry, $result);
    }

    public function testUpdateUserDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('users')
            ->execute()
            ->fetchAll();

        //test that dev cant update user
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'username' => 'unittestUpdate',
            'abbr' => 'WAR',
            'teams' => ['1'], //req
            'locale' => 'de',   //req
            'type' => 'DEV'    //req
        ];
        $this->client->request('POST', '/user/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('users')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testDeleteUserAction()
    {
        //create user for deletion
        $this->queryBuilder
            ->insert('users')
            ->values(
                [
                    'id' => '?',
                    'username' => '?',
                    'type' => '?',
                ]
            )
            ->setParameter(0, 42)
            ->setParameter(1, 'userForDeletetion')
            ->setParameter(2, 'DEV')
            ->execute();
        //Use ID of 42 to avoid problems when adding a new user for testing
        $parameter = ['id' => 42,];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request('POST', '/user/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Dataset could not be removed. ',
        ];
        $this->client->request('POST', '/user/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteUserActionDevNotAllowed()
    {
        //test that dev cant delete user and db is the same after that
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('users')
            ->execute()
            ->fetchAll();
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/user/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('users')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    /**
     * Returns all Users for dev and non dev
     * unique feature = returns teams for user
     *
     */
    public function testGetUsersAction()
    {
        $expectedJson = array(
            0 => array(
                'user' => array(
                    'username' => 'developer',
                    'type' => 'DEV',
                    'abbr' => 'NPL',
                    'locale' => 'de',
                    'teams' => array(),
                ),
            ),
            1 => array(
                'user' => array(
                    'username' => 'i.myself',
                    'type' => 'PL',
                    'abbr' => 'IMY',
                    'locale' => 'de',
                    'teams' => array(
                        0 => 1,
                    ),
                ),
            ),
        );
        $this->client->request('GET', '/getAllUsers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }


    //-------------- teams routes ----------------------------------------
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

    //-------------- customer routes ----------------------------------------

    public function testSaveCustomerAction()
    {
        $parameter = [
            'name' => 'testCustomer',
            'teams' => [2],
        ];
        $this->client->request('POST', '/customer/save', $parameter);
        $expectedJson = [
            1 => 'testCustomer',
            4 => [2],
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        //test that customer was added to db
        //test that teams_customers entry was created
        $this->queryBuilder->select('c.name', 'tc.team_id')
            ->from('customers', 'c')
            ->leftJoin('c', 'teams_customers', 'tc', 'c.id = tc.customer_id')
            ->where('c.name = ?')
            ->setParameter(0, 'testCustomer');
        $result1 = $this->queryBuilder->execute()->fetchAll();
        $expectedDBentry = [
            [
                'name' => 'testCustomer',
                'team_id' => 2,
            ]
        ];
        $this->assertArraySubset($expectedDBentry, $result1);
    }

    public function testSaveCustomerActionDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('customers')
            ->execute()
            ->fetchAll();
        //test that dev cant save customer
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testCustomer',
            'teams' => [2],
        ];
        $this->client->request('POST', '/customer/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('customers')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testUpdateCustomer()
    {
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestCustomer',
            'teams' => [2],
        ];
        $this->client->request('POST', '/customer/save', $parameter);
        $expectedJson = [
            1 => 'updatedTestCustomer',
            4 => [2],
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        //validate updated entry in db
        $this->queryBuilder->select('c.name', 'tc.team_id')
            ->from('customers', 'c')
            ->leftJoin('c', 'teams_customers', 'tc', 'c.id = tc.customer_id')
            ->where('c.id = ?')
            ->setParameter(0, 1);
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDBentry = [
            [
                'name' => 'updatedTestCustomer',
                'team_id' => 2,
            ]
        ];
        $this->assertArraySubset($expectedDBentry, $result);
    }

    public function testUpdateCustomerDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('customers')
            ->execute()
            ->fetchAll();

        //test that dev cant update team
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestCustomer',
            'teams' => [2],
        ];
        $this->client->request('POST', '/customer/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');

        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('customers')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testDeleteCustomerAction()
    {
        //create customer for deletion
        $this->queryBuilder
            ->insert('customers')
            ->values(
                [
                    'id' => '?',
                    'name' => '?',
                ]
            )
            ->setParameter(0, 42)
            ->setParameter(1, 'customerForDeletion')
            ->execute();
        //Use ID of 42 to avoid problems when adding a new customer for testing
        $parameter = ['id' => 42,];

        //first delete
        $expectedJson = [
            'success' => true,
        ];
        $this->client->request('POST', '/customer/delete', $parameter);
        $this->assertStatusCode(200, 'First delete did not return expected 200');
        $this->assertJsonStructure($expectedJson);

        //  second delete
        $expectedJson2 = [
            'message' => 'Dataset could not be removed. ',
        ];
        $this->client->request('POST', '/customer/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteCustomerActionDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('customers')
            ->execute()
            ->fetchAll();
        //test that dev cant delete team
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/customer/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('customers')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testGetCustomersAction()
    {
        $expectedJson = [
            [
                'customer' => [
                    'name' => 'Der Bäcker von nebenan',
                    'teams' => [1],
                ],
            ],
            [
                'customer' => [
                    'name' => 'Der Globale Customer',
                    'teams' => [],
                ],
            ],
            [
                'customer' => [
                    'name' => 'Der nebenan vom Bäcker',
                    'teams' => [2],
                ],
            ],
        ];
        $this->client->request('GET', '/getAllCustomers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    //-------------- project routes ----------------------------------------

    public function testSaveProjectAction()
    {
        $parameter = [
            'name' => 'testProject', //req
            'customer' => 1, //req
        ];

        $this->client->request('POST', '/project/save', $parameter);

        $expectedJson = [
            1 => 'testProject',
            2 => 1,
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        $this->queryBuilder->select('name', 'customer_id')
            ->from('projects')->where('name = ?')
            ->setParameter(0, 'testProject');
        $result = $this->queryBuilder->execute()->fetchAll();

        $expectedDBentry = [
            [
                'name' => 'testProject',
                'customer_id' => 1,
            ]
        ];
        $this->assertArraySubset($expectedDBentry, $result);

    }

    public function testSaveProjectActionDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('projects')
            ->execute()
            ->fetchAll();
        //test that dev cant save project
        $parameter = [
            'name' => 'testProject', //req
            'customer' => 1, //req
        ];
        $this->logInSession('developer');
        $this->client->request('POST', '/project/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('projects')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testUpdateProject()
    {
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestProject', //req
            'customer' => 2, //req
        ];
        $this->client->request('POST', '/project/save', $parameter);
        $expectedJson = [
            0 => 1,
            1 => 'updatedTestProject',
            2 => 1,
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        // validate updated entry in db
        $this->queryBuilder->select('name', 'customer_id')
            ->from('projects')->where('id = ?')
            ->setParameter(0, 1);
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDBentry = [
            [
                'name' => 'updatedTestProject',
                'customer_id' => 1,
            ]
        ];
        $this->assertArraySubset($expectedDBentry, $result);
    }

    public function testUpdateProjectDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('projects')
            ->execute()
            ->fetchAll();

        //test that dev cant update project
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestProject', //req
            'customer' => 2, //req
        ];
        $this->client->request('POST', '/project/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');

        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('projects')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testDeleteProjectAction()
    {
        $parameter = ['id' => 2,];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request('POST', '/project/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Dataset could not be removed. ',
        ];
        $this->client->request('POST', '/project/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteProjectActionDevNotAllowed()
    {
        //test that dev cant delete project and db is the same after that
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('projects')
            ->execute()
            ->fetchAll();

        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/project/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');

        $newDb = $this->queryBuilder
            ->select('*')
            ->from('projects')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    //-------------- activities routes ----------------------------------------
    public function testSaveActivityAction()
    {
        $parameter = [
            'name' => 'Lachen', //req
            'factor' => 2, //req
        ];
        $this->client->request('POST', '/activity/save', $parameter);
        $expectedJson = array(
            1 => 'Lachen',
            3 => '2',
        );
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        //assert that activity was saved
        $this->queryBuilder->select('name', 'factor')
            ->from('activities')->where('name = ?')
            ->setParameter(0, 'Lachen');
        $result = $this->queryBuilder->execute()->fetchAll();

        $expectedDBentry = [
            [
                'name' => 'Lachen',
                'factor' => 2,
            ]
        ];
        $this->assertArraySubset($expectedDBentry, $result);
    }

    public function testSaveActivityActionDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('activities')
            ->execute()
            ->fetchAll();
        //test that dev cant save activities
        $parameter = [
            'name' => 'testActivities', //req
            'factor' => 2, //req
        ];
        $this->logInSession('developer');
        $this->client->request('POST', '/activity/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('activities')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testUpdateActivityAction()
    {
        $parameter = [
            'id' => 1,  //req
            'name' => 'update', //req
            'factor' => 2, //req
        ];
        $expectedJson = array(
            1 => 'update',
            3 => '2',
        );
        $this->client->request('POST', '/activity/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        //assert that activity was updated
        $this->queryBuilder->select('name', 'factor')
            ->from('activities')->where('name = ?')
            ->setParameter(0, 'update');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDBentry = array(
            0 => array(
                'name' => 'update',
                'factor' => 2,
            ),
        );
        $this->assertArraySubset($expectedDBentry, $result);
    }

    public function testUpdateActivityActionDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('activities')
            ->execute()
            ->fetchAll();
        //test that dev cant save activities
        $parameter = [
            'id' => 1,
            'name' => 'testActivities', //req
            'factor' => 2, //req
        ];
        $this->logInSession('developer');
        $this->client->request('POST', '/activity/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('activities')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testDeleteActivityAction()
    {
        //create activity for deletion
        $this->queryBuilder
            ->insert('activities')
            ->values(
                [
                    'id' => '?',
                    'name' => '?',
                    'factor' => '?',
                ]
            )
            ->setParameter(0, 42)
            ->setParameter(1, 'activityForDeletion')
            ->setParameter(2, '1')
            ->execute();
        //Use ID of 42 to avoid problems when adding a new activity for testing
        $parameter = ['id' => 42];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request('POST', '/activity/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Dataset could not be removed. ',
        ];
        $this->client->request('POST', '/activity/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteActivityActionDevNotAllowed()
    {
        //test that dev cant delete activity and db is the same after that
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('activities')
            ->execute()
            ->fetchAll();

        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request('POST', '/activity/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');

        $newDb = $this->queryBuilder
            ->select('*')
            ->from('activities')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    //-------------- contract routes ----------------------------------------
    public function testSaveContractAction()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => '2019-11-01', //req
            'hours_0' => 1,
            'hours_1' => 2,
            'hours_2' => 3,
            'hours_3' => 4.3,
            'hours_4' => 5,
            'hours_5' => 6,
            'hours_6' => 7,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(200);
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2019-11-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDBentry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2019-11-01',
                'hours_0' => 1.0,
                'hours_1' => 2.0,
                'hours_2' => 3.0,
                'hours_3' => 4.3,
                'hours_4' => 5.0,
                'hours_5' => 6.0,
                'hours_6' => 7.0,
            ),
        );
        $this->assertArraySubset($expectedDBentry, $result);
    }

    public function testSaveContractActionDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->execute()
            ->fetchAll();
        //test that dev cant save contract
        $parameter = [
            'user_id' => '1', //req
            'start' => '2019-11-01', //req
            'hours_0' => 1,
            'hours_1' => 2,
            'hours_2' => 3,
            'hours_3' => 4.3,
            'hours_4' => 5,
            'hours_5' => 6,
            'hours_6' => 7,
        ];
        $this->logInSession('developer');
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testUpdateContract()
    {
        $parameter = [
            'id' => 1,
            'user_id' => '2', //req
            'start' => '1000-01-01', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $expectedJson = [
            0 => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        // validate updated contract in db
        $this->queryBuilder->select('*')
            ->from('contracts')->where('id = ?')
            ->setParameter(0, 1);
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDBentry = [
            [
                'user_id' => 2,
                'start' => '1000-01-01',
                'hours_0' => 0,
                'hours_1' => 0,
                'hours_2' => 0,
                'hours_3' => 0,
                'hours_4' => 0,
                'hours_5' => 0,
                'hours_6' => 0,
            ]
        ];
        $this->assertArraySubset($expectedDBentry, $result);
    }

    public function testUpdateContractDevNotAllowed()
    {
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->execute()
            ->fetchAll();
        //test that dev cant update project
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'user_id' => '2', //req
            'start' => '1000-01-01', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        //test that database ist still the same
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testDeleteContractAction()
    {
        $parameter = ['id' => 1,];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request('POST', '/contract/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Dataset could not be removed. ',
        ];
        $this->client->request('POST', '/contract/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteContractActionDevNotAllowed()
    {
        //test that dev cant delete project and db is the same after that
        $oldDb = $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->execute()
            ->fetchAll();
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/contract/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $newDb = $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->execute()
            ->fetchAll();
        $this->assertSame($oldDb, $newDb);
    }

    public function testGetContractAction()
    {
        $expectedJson = array(
            0 => array(
                'contract' => array(
                    'id' => 2,
                    'user_id' => 2,
                    'start' => '1020-01-01',
                    'end' => '2020-01-01',
                    'hours_0' => 1,
                    'hours_1' => 1,
                    'hours_2' => 1,
                    'hours_3' => 1,
                    'hours_4' => 1,
                    'hours_5' => 1,
                    'hours_6' => 1,
                ),
            ),
            1 => array(
                'contract' => array(
                    'id' => 1,
                    'user_id' => 1,
                    'start' => '2020-01-01',
                    'hours_0' => 0,
                    'hours_1' => 8,
                    'hours_2' => 8,
                    'hours_3' => 8,
                    'hours_4' => 8,
                    'hours_5' => 8,
                    'hours_6' => 0,
                ),
            ),
        );
        $this->client->request('GET', '/getContracts');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }
}
