<?php

namespace Tests\Controller;

use Tests\Base;

class AdminControllerTest extends Base
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
        $this->setInitialDbState('users');
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
        $this->assertDbState('users');
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
        $expectedDbEntry = array(
            0 => array(
                'id' => 1,
                'username' => 'unittestUpdate',
                'abbr' => 'WAR',
                'type' => 'DEV',
                'jira_token' => null,
                'locale' => 'de',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateUserDevNotAllowed()
    {
        $this->setInitialDbState('users');
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
        $this->assertDbState('users');
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
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/user/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteUserActionDevNotAllowed()
    {
        $this->setInitialDbState('users');
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/user/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('users');
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
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/team/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);

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

        $expectedDbEntry = [
            [
                'name' => 'testSaveTeamAction',
                'lead_user_id' => 1,
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);

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
        $expectedDbEntry = [
            [
                'name' => 'updatedKuchenbäcker',
                'lead_user_id' => 2,
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateTeamPermission()
    {
        $this->setInitialDbState('teams');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'testSaveTeamActionFromNotPL', //opt
            'lead_user_id' => 1, //req
        ];
        $this->client->request('POST', '/team/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('teams');
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
        $expectedDbEntry = [
            [
                'name' => 'testCustomer',
                'team_id' => 2,
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result1);
    }

    public function testSaveCustomerActionDevNotAllowed()
    {
        $this->setInitialDbState('customers');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testCustomer',
            'teams' => [2],
        ];
        $this->client->request('POST', '/customer/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('customers');
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
        $expectedDbEntry = [
            [
                'name' => 'updatedTestCustomer',
                'team_id' => 2,
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateCustomerDevNotAllowed()
    {
        $this->setInitialDbState('customers');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestCustomer',
            'teams' => [2],
        ];
        $this->client->request('POST', '/customer/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('customers');
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
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/customer/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteCustomerActionDevNotAllowed()
    {
        $this->setInitialDbState('customers');
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/customer/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('customers');
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

        $expectedDbEntry = [
            [
                'name' => 'testProject',
                'customer_id' => 1,
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);

    }

    public function testSaveProjectActionDevNotAllowed()
    {
        $this->setInitialDbState('projects');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testProject', //req
            'customer' => 1, //req
        ];
        $this->client->request('POST', '/project/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('projects');
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
        $expectedDbEntry = [
            [
                'name' => 'updatedTestProject',
                'customer_id' => 1,
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateProjectDevNotAllowed()
    {
        $this->setInitialDbState('projects');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestProject', //req
            'customer' => 2, //req
        ];
        $this->client->request('POST', '/project/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('projects');
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
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/project/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteProjectActionDevNotAllowed()
    {
        $this->setInitialDbState('projects');
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/project/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('projects');
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

        $expectedDbEntry = [
            [
                'name' => 'Lachen',
                'factor' => 2,
            ]
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveActivityActionDevNotAllowed()
    {
        $this->setInitialDbState('activities');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testActivities', //req
            'factor' => 2, //req
        ];
        $this->client->request('POST', '/activity/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('activities');
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
        $expectedDbEntry = array(
            0 => array(
                'name' => 'update',
                'factor' => 2,
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateActivityActionDevNotAllowed()
    {
        $this->setInitialDbState('activities');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'testActivities', //req
            'factor' => 2, //req
        ];
        $this->client->request('POST', '/activity/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('activities');
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
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/activity/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteActivityActionDevNotAllowed()
    {
        $this->setInitialDbState('activities');
        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request('POST', '/activity/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('activities');
    }

    //-------------- contract routes ----------------------------------------
    public function testSaveContractAction()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => '2025-11-01', //req
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
        $this->assertJsonStructure([5]);
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2025-11-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2025-11-01',
                'hours_0' => 1.0,
                'hours_1' => 2.0,
                'hours_2' => 3.0,
                'hours_3' => 4.3,
                'hours_4' => 5.0,
                'hours_5' => 6.0,
                'hours_6' => 7.0,
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2020-02-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2020-02-01',
                'end' => '2025-10-31'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionStartNotFirstOfMonth()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => '2025-11-11', //req
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
        $this->assertJsonStructure([5]);
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2025-11-11');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2025-11-11',
                'hours_0' => 1.0,
                'hours_1' => 2.0,
                'hours_2' => 3.0,
                'hours_3' => 4.3,
                'hours_4' => 5.0,
                'hours_5' => 6.0,
                'hours_6' => 7.0,
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2020-02-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 1,
                'start' => '2020-02-01',
                'end' => '2025-11-10'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionAlterExistingContract()
    {
        $parameter = [
            'user_id' => '3', //req
            'start' => '0700-08-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // look at old contract
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '0700-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '0700-07-31',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionOldContractStartsDuringNewWithoutEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2020-03-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionOldContractStartsDuringNew()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2020-03-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'end' => '2020-02-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionOldContractWithoutEndStartsDuringNew()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'end' => '2022-03-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionOldContractWithoutEndStartsDuringNewWithoutEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionOldContractEndStartsDuringNewEndWithNewContractEndingAfterOld()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2022-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2019-01-01', //req
            'end' => '2027-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');
    }

    public function testSaveContractActionNewContractStartsDuringOldWithEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2022-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2021-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');
    }

    public function testSaveContractActionNewContractWithEndDuringOldStartsDuringOldWithEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2024-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2021-01-01', //req
            'end' => '2022-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');
    }

    public function testSaveContractActionNewContractWithEndAfterOldStartsDuringOldWithEnd()
    {
        $parameterContract1 = [
            'user_id' => '3', //req
            'start' => '2020-01-01', //req
            'end' => '2024-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', //req
            'start' => '2021-01-01', //req
            'end' => '2030-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request('POST', '/contract/save', $parameterContract2);
        $this->assertStatusCode(406);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');
    }

    public function testSaveContractActionOldContractStartsInFutureAfterNewEnds()
    {
        $parameterContract = [
            'user_id' => '3', //req
            'start' => '500-01-01', //req
            'end' => '600-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '0700-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => NULL
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionOldContractWithEndStartsInFutureAfterNewEnds()
    {
        $parameterContract = [
            'user_id' => '2', //req
            'start' => '500-01-01', //req
            'end' => '600-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionOldContractWithEndbeforeStartNewContract()
    {
        $parameterContract = [
            'user_id' => '2', //req
            'start' => '5000-01-01', //req
            'end' => '6000-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionUpdateOldContract()
    {
        $parameterContract = [
            'user_id' => '3', //req
            'start' => '5000-01-01', //req
            'end' => '6000-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '700-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '4999-12-31',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionUpdateOldContractNewWithoutEnd()
    {
        $parameterContract = [
            'user_id' => '3', //req
            'start' => '5000-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '700-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '4999-12-31',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionOldContractWithEndbeforeStartNewContractOpenEnd()
    {
        $parameterContract = [
            'user_id' => '2', //req
            'start' => '5000-01-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01'
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveContractActionMultipleOpenEndedContracts()
    {
        $values = [
            'user_id' => '3',
            'start' =>  "'2020-04-01'",
            'hours_0' => '1',
            'hours_1' => '2',
            'hours_2' => '3',
            'hours_3' => '4',
            'hours_4' => '5',
            'hours_5' => '5',
            'hours_6' => '5',
        ];

        $this->queryBuilder
            ->insert('contracts')
            ->values($values)
            ->execute();

        $parameter = [
            'user_id' => '3', //req
            'start' => '2020-08-01', //req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Für den Nutzer besteht mehr als ein unbefristeter Vertrag.');
    }

    public function testSaveContractActionDevNotAllowed()
    {
        $this->setInitialDbState('contracts');
        $this->logInSession('developer');
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
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('contracts');
    }

    public function testUpdateContract()
    {
        $parameter = [
            'id' => 1,
            'user_id' => '3', //req
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
        $this->assertStatusCode(200);
        $this->assertJsonStructure([1]);
        // validate updated contract in db
        $this->queryBuilder->select('*')
            ->from('contracts')->where('id = ?')
            ->setParameter(0, 1);
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = [
            [
                'user_id' => 3,
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
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testCreateContractUserNotExist()
    {
        $parameter = [
            'user_id' => '42', //req
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
        $this->assertStatusCode(406);
        $this->assertMessage('Bitte geben Sie einen gültigen Benutzer an.');
    }

    public function testCreateContractNoEntry()
    {
        $parameter = [
            'id' => '100',
            'user_id' => '1', //req
            'start' => '1000-01-01', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $expectedJson = ['message' => 'No entry for id.'];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(404);
        $this->assertJsonStructure($expectedJson);
    }

    public function testCreateContractInvalidStartDate()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => 'test', //req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Bitte geben Sie einen gültigen Vertragsbeginn an.');
    }

    public function testCreateContractGreaterStartThenEnd()
    {
        $parameter = [
            'user_id' => '1', //req
            'start' => '1000-01-01', //req
            'end' => '0900-01-01',
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('Das Vertragsende muss nach dem Vertragsbeginn liegen.');
    }

    public function testUpdateContractDevNotAllowed()
    {
        $this->setInitialDbState('contracts');
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
        $this->assertDbState('contracts');
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
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/contract/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteContractActionDevNotAllowed()
    {
        $this->setInitialDbState('contracts');
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/contract/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('contracts');
    }

    public function testGetContractAction()
    {
        $expectedJson = [
            [
                'contract' => [
                    'id' => 3,
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
                ],
            ],
            [
                'contract' => [
                    'id' => 1,
                    'user_id' => 1,
                    'start' => '2020-01-01',
                    'end' => '2020-01-31',
                    'hours_0' => 0,
                    'hours_1' => 1,
                    'hours_2' => 2,
                    'hours_3' => 3,
                    'hours_4' => 4,
                    'hours_5' => 5,
                    'hours_6' => 0,
                ],
            ],
            [
                'contract' => [
                    'id' => 2,
                    'user_id' => 1,
                    'start' => '2020-02-01',
                    'hours_0' => 0,
                    'hours_1' => 1.1,
                    'hours_2' => 2.2,
                    'hours_3' => 3.3,
                    'hours_4' => 4.4,
                    'hours_5' => 5.5,
                    'hours_6' => 0.5,
                ],
            ],
            [
                'contract' => [
                    'id' => 4,
                    'user_id' => 3,
                    'start' => '0700-01-01',
                    'hours_0' => 1,
                    'hours_1' => 2,
                    'hours_2' => 3,
                    'hours_3' => 4,
                    'hours_4' => 5,
                    'hours_5' => 5,
                    'hours_6' => 5,
                ],
            ],
        ];

        $this->client->request('GET', '/getContracts');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    //-------------- ticketSystems routes ----------------------------------------
    public function testGetTicketSystemsAction()
    {
        $expectedJson = array(
            0 => array(
                'ticketSystem' => array(
                    'name' => 'testSystem',
                ),
            ),
        );
        $this->client->request('GET', '/getTicketSystems');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testSaveTicketSystemAction()
    {
        $parameter = [
            'name' => 'testSaveTicketSystem', //req
            'url' => '',
            'type' => '',
            'ticketUrl' => '',
            'login' => '',
            'password' => '',
            'publicKey' => '',
            'privateKey' => '',
        ];
        $expectedJson = array(
            'name' => 'testSaveTicketSystem',
        );
        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder
            ->select('*')
            ->from('ticket_systems')
            ->where('name = ?')
            ->setParameter(0, 'testSaveTicketSystem');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'name' => 'testSaveTicketSystem',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveTicketSystemActionDevNotAllowed()
    {
        $this->setInitialDbState('ticket_systems');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testSaveTicketSystem', //req
            'url' => '',
            'type' => '',
            'ticketUrl' => '',
            'login' => '',
            'password' => '',
            'publicKey' => '',
            'privateKey' => '',
        ];
        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('ticket_systems');
    }

    public function testUpdateTicketSystem()
    {
        $parameter = [
            'id' => 1,
            'name' => 'testSaveTicketSystemUpdate', //req
            'url' => '',
            'type' => '',
            'ticketUrl' => '',
            'login' => '',
            'password' => '',
            'publicKey' => '',
            'privateKey' => '',
        ];
        $expectedJson = array(
            'name' => 'testSaveTicketSystemUpdate',
        );
        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder
            ->select('*')
            ->from('ticket_systems')
            ->where('name = ?')
            ->setParameter(0, 'testSaveTicketSystemUpdate');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'name' => 'testSaveTicketSystemUpdate',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateTicketSystemDevNotAllowed()
    {
        $this->setInitialDbState('ticket_systems');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'testSaveTicketSystemUpdate', //req
            'url' => '',
            'type' => '',
            'ticketUrl' => '',
            'login' => '',
            'password' => '',
            'publicKey' => '',
            'privateKey' => '',
        ];
        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('ticket_systems');
    }

    public function testDeleteTicketSystemAction()
    {
        $parameter = ['id' => 1,];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request('POST', '/ticketsystem/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/ticketsystem/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteTicketSystemActionDevNotAllowed()
    {
        $this->setInitialDbState('ticket_systems');
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/ticketsystem/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('ticket_systems');
    }

    //-------------- presets routes ----------------------------------------
    public function testGetPresetsAction()
    {
        $expectedJson = array(
            0 => array(
                'preset' => array(
                    'id' => 1,
                    'name' => 'Urlaub',
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => 'Urlaub',
                ),
            ),
        );
        $this->client->request('GET', '/getAllPresets');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testSavePresetAction()
    {
        $parameter = [
            'name' => 'newPreset', //req
            'customer' => 1, //req
            'project' => 1, //req
            'activity' => 1, //req
            'description' => '',    //req
        ];
        $expectedJson = array(
            'name' => 'newPreset',
            'customer' => 1,
            'project' => 1,
            'activity' => 1,
            'description' => '',
        );
        $this->client->request('POST', '/preset/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder
            ->select('*')
            ->from('presets')
            ->where('name = ?')
            ->setParameter(0, 'newPreset');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'name' => 'newPreset',
                'customer_id' => 1,
                'project_id' => 1,
                'activity_id' => 1,
                'description' => '',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSavePresetActionDevNotAllowed()
    {
        $this->setInitialDbState('presets');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'newPreset', //req
            'customer' => 1, //req
            'project' => 1, //req
            'activity' => 1, //req
            'description' => '',    //reg
        ];
        $this->client->request('POST', '/contract/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('presets');
    }

    public function testUpdatePreset()
    {
        $parameter = [
            'id' => 1,
            'name' => 'newPresetUpdated', //req
            'customer' => 1, //req
            'project' => 1, //req
            'activity' => 1, //req
            'description' => '',    //reg
        ];
        $expectedJson = array(
            'name' => 'newPresetUpdated',
            'customer' => 1,
            'project' => 1,
            'activity' => 1,
            'description' => '',
        );
        $this->client->request('POST', '/preset/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder
            ->select('*')
            ->from('presets')
            ->where('name = ?')
            ->setParameter(0, 'newPresetUpdated');
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'name' => 'newPresetUpdated',
                'customer_id' => 1,
                'project_id' => 1,
                'activity_id' => 1,
                'description' => '',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdatePresetDevNotAllowed()
    {
        $this->setInitialDbState('presets');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'newPresetUpdated', //req
            'customer' => 1, //req
            'project' => 1, //req
            'activity' => 1, //req
            'description' => '',    //reg
        ];
        $this->client->request('POST', '/preset/save', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('presets');
    }

    public function testDeletePresetAction()
    {
        $parameter = ['id' => 1,];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request('POST', '/preset/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request('POST', '/preset/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeletePresetActionDevNotAllowed()
    {
        $this->setInitialDbState('presets');
        $this->logInSession('developer');
        $parameter = ['id' => 1,];
        $this->client->request('POST', '/preset/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('presets');
    }
}
