<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AdminControllerTest extends AbstractWebTestCase
{
    // -------------- users routes ----------------------------------------
    public function testSaveUserAction(): void
    {
        $parameter = [
            'username' => 'unittest',
            'abbr' => 'WAS',
            'teams' => ['2'], // req
            'locale' => 'en',   // req
            'type' => 'PL',    // req
        ];
        $expectedJson = [
            1 => 'unittest',
            2 => 'WAS',
            3 => 'PL',
        ];

        // Simple POST request should work with authentication from setUp
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);

        // Assert successful response
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testSaveUserActionDevNotAllowed(): void
    {
        $this->setInitialDbState('users');
        $this->logInSession('developer');
        $parameter = [
            'username' => 'unittest',
            'abbr' => 'ZZZ',  // Changed to unique value to avoid validation conflict
            'teams' => [1], // req
            'locale' => 'en',   // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('users');
    }

    public function testUpdateUser(): void
    {
        $parameter = [
            'id' => 1,
            'username' => 'unittestUpdate',
            'abbr' => 'WAR',
            'teams' => ['1'], // req
            'locale' => 'de',   // req
            'type' => 'DEV',    // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);

        $expectedJson = [
            1 => 'unittestUpdate',
            2 => 'WAR',
            3 => 'DEV',
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        // validate updated entry in db
        $this->queryBuilder->select('*')
            ->from('users')->where('id = ?')
            ->setParameter(0, 1)
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'id' => 1,
                'username' => 'unittestUpdate',
                'abbr' => 'WAR',
                'type' => 'DEV',
                'jira_token' => null,
                'locale' => 'de',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateUserDevNotAllowed(): void
    {
        $this->setInitialDbState('users');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'username' => 'unittestUpdate',
            'abbr' => 'WAR',
            'teams' => ['1'], // req
            'locale' => 'de',   // req
            'type' => 'DEV',    // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('users');
    }

    public function testDeleteUserAction(): void
    {
        // create user for deletion
        $this->queryBuilder
            ->insert('users')
            ->values(
                [
                    'id' => '?',
                    'username' => '?',
                    'type' => '?',
                ],
            )
            ->setParameter(0, 42)
            ->setParameter(1, 'userForDeletetion')
            ->setParameter(2, 'DEV')
            ->executeStatement()
        ;
        // Use ID of 42 to avoid problems when adding a new user for testing
        $parameter = ['id' => 42];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteUserActionDevNotAllowed(): void
    {
        $this->setInitialDbState('users');
        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('users');
    }

    /**
     * Returns all Users for dev and non dev
     * unique feature = returns teams for user.
     */
    public function testGetUsersAction(): void
    {
        // Simple and direct approach that works
        $expectedJson = [
            0 => [
                'user' => [
                    'username' => 'developer',
                    'type' => 'DEV',
                    'abbr' => 'NPL',
                    'locale' => 'de',
                    'teams' => [],
                ],
            ],
            1 => [
                'user' => [
                    'username' => 'i.myself',
                    'type' => 'PL',
                    'abbr' => 'IMY',
                    'locale' => 'de',
                    'teams' => [
                        0 => 1,
                    ],
                ],
            ],
        ];

        // Make the request - should work with our authentication from setUp
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllUsers');

        // Assert response status and expected JSON structure
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    // -------------- teams routes ----------------------------------------
    public function testGetTeamsAction(): void
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

        // Simple request should work with authentication from setUp
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllTeams');

        // Assert successful response
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testDeleteTeamAction(): void
    {
        $parameter = ['id' => 1];

        // first delete
        $expectedJson = [
            'success' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/delete', $parameter);
        $this->assertStatusCode(200, 'First delete did not return expected 200');
        $this->assertJsonStructure($expectedJson);

        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);

        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testSaveTeamAction(): void
    {
        $parameter = [
            'name' => 'testSaveTeamAction', // opt
            'lead_user_id' => 1, // req
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);

        $expectedJson = [
            1 => 'testSaveTeamAction',
            2 => 1,
        ];

        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        $this->queryBuilder->select('name', 'lead_user_id')
            ->from('teams')->where('name = ?')
            ->setParameter(0, 'testSaveTeamAction')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();

        $expectedDbEntry = [
            [
                'name' => 'testSaveTeamAction',
                'lead_user_id' => 1,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        $this->logInSession('developer');
        $parameter = [
            'name' => 'testSaveTeamActionFromNotPL', // opt
            'lead_user_id' => 1, // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test for updating a existing team
     * Uses the same route and method as SaveTeamAction
     * When sending a existing id, the team with that id gets updated.
     */
    public function testUpdateTeam(): void
    {
        $parameter = [
            'lead_user_id' => 2, // req
            'name' => 'updatedKuchenbäcker', // opt
            'id' => 1,  // for update req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $expectedJson = [
            0 => 1,
            1 => 'updatedKuchenbäcker',
            2 => 2,
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        // validate updated entry in db
        $this->queryBuilder->select('name', 'lead_user_id')
            ->from('teams')->where('id = ?')
            ->setParameter(0, 1)
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            [
                'name' => 'updatedKuchenbäcker',
                'lead_user_id' => 2,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateTeamPermission(): void
    {
        $this->setInitialDbState('teams');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'testSaveTeamActionFromNotPL', // opt
            'lead_user_id' => 1, // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('teams');
    }

    // -------------- customer routes ----------------------------------------

    public function testSaveCustomerAction(): void
    {
        $parameter = [
            'name' => 'testCustomer',
            'teams' => [2],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $expectedJson = [
            1 => 'testCustomer',
            4 => [2],
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        // test that customer was added to db
        // test that teams_customers entry was created
        $this->queryBuilder->select('c.name', 'tc.team_id')
            ->from('customers', 'c')
            ->leftJoin('c', 'teams_customers', 'tc', 'c.id = tc.customer_id')
            ->where('c.name = ?')
            ->setParameter(0, 'testCustomer')
        ;
        $result1 = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            [
                'name' => 'testCustomer',
                'team_id' => 2,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result1);
    }

    public function testSaveCustomerActionDevNotAllowed(): void
    {
        $this->setInitialDbState('customers');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testCustomer',
            'teams' => [2],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('customers');
    }

    public function testUpdateCustomer(): void
    {
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestCustomer',
            'teams' => [2],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $expectedJson = [
            1 => 'updatedTestCustomer',
            4 => [2],
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        // validate updated entry in db
        $this->queryBuilder->select('c.name', 'tc.team_id')
            ->from('customers', 'c')
            ->leftJoin('c', 'teams_customers', 'tc', 'c.id = tc.customer_id')
            ->where('c.id = ?')
            ->setParameter(0, 1)
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            [
                'name' => 'updatedTestCustomer',
                'team_id' => 2,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateCustomerDevNotAllowed(): void
    {
        $this->setInitialDbState('customers');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestCustomer',
            'teams' => [2],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('customers');
    }

    public function testDeleteCustomerAction(): void
    {
        // create customer for deletion
        $this->queryBuilder
            ->insert('customers')
            ->values(
                [
                    'id' => '?',
                    'name' => '?',
                ],
            )
            ->setParameter(0, 42)
            ->setParameter(1, 'customerForDeletion')
            ->executeStatement()
        ;
        // Use ID of 42 to avoid problems when adding a new customer for testing
        $parameter = ['id' => 42];

        // first delete
        $expectedJson = [
            'success' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/delete', $parameter);
        $this->assertStatusCode(200, 'First delete did not return expected 200');
        $this->assertJsonStructure($expectedJson);

        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteCustomerActionDevNotAllowed(): void
    {
        $this->setInitialDbState('customers');
        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('customers');
    }

    public function testGetCustomersAction(): void
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
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllCustomers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    // -------------- project routes ----------------------------------------

    public function testSaveProjectAction(): void
    {
        $parameter = [
            'name' => 'testProject', // req
            'customer' => 1, // req
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);

        $expectedJson = [
            1 => 'testProject',
            2 => 1,
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        $this->queryBuilder->select('name', 'customer_id')
            ->from('projects')->where('name = ?')
            ->setParameter(0, 'testProject')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();

        $expectedDbEntry = [
            [
                'name' => 'testProject',
                'customer_id' => 1,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveProjectActionDevNotAllowed(): void
    {
        $this->setInitialDbState('projects');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testProject', // req
            'customer' => 1, // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('projects');
    }

    public function testUpdateProject(): void
    {
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestProject', // req
            'customer' => 2, // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
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
            ->setParameter(0, 1)
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            [
                'name' => 'updatedTestProject',
                'customer_id' => 1,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateProjectDevNotAllowed(): void
    {
        $this->setInitialDbState('projects');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'updatedTestProject', // req
            'customer' => 2, // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('projects');
    }

    public function testDeleteProjectAction(): void
    {
        $parameter = ['id' => 2];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteProjectActionDevNotAllowed(): void
    {
        $this->setInitialDbState('projects');
        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('projects');
    }

    // -------------- activities routes ----------------------------------------
    public function testSaveActivityAction(): void
    {
        $parameter = [
            'name' => 'Lachen', // req
            'factor' => 2, // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $expectedJson = [
            1 => 'Lachen',
            3 => '2',
        ];
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        // assert that activity was saved
        $this->queryBuilder->select('name', 'factor')
            ->from('activities')->where('name = ?')
            ->setParameter(0, 'Lachen')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();

        $expectedDbEntry = [
            [
                'name' => 'Lachen',
                'factor' => 2,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveActivityActionDevNotAllowed(): void
    {
        $this->setInitialDbState('activities');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testActivities', // req
            'factor' => 2, // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('activities');
    }

    public function testUpdateActivityAction(): void
    {
        $parameter = [
            'id' => 1,  // req
            'name' => 'update', // req
            'factor' => 2, // req
        ];
        $expectedJson = [
            1 => 'update',
            3 => '2',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        // assert that activity was updated
        $this->queryBuilder->select('name', 'factor')
            ->from('activities')->where('name = ?')
            ->setParameter(0, 'update')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'name' => 'update',
                'factor' => 2,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateActivityActionDevNotAllowed(): void
    {
        $this->setInitialDbState('activities');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'testActivities', // req
            'factor' => 2, // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('activities');
    }

    public function testDeleteActivityAction(): void
    {
        // create activity for deletion
        $this->queryBuilder
            ->insert('activities')
            ->values(
                [
                    'id' => '?',
                    'name' => '?',
                    'factor' => '?',
                ],
            )
            ->setParameter(0, 42)
            ->setParameter(1, 'activityForDeletion')
            ->setParameter(2, '1')
            ->executeStatement()
        ;
        // Use ID of 42 to avoid problems when adding a new activity for testing
        $parameter = ['id' => 42];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteActivityActionDevNotAllowed(): void
    {
        $this->setInitialDbState('activities');
        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('activities');
    }

    // -------------- contract routes ----------------------------------------
    public function testSaveContractAction(): void
    {
        $parameter = [
            'user_id' => '1', // req
            'start' => '2025-11-01', // req
            'hours_0' => 1,
            'hours_1' => 2,
            'hours_2' => 3,
            'hours_3' => 4.3,
            'hours_4' => 5,
            'hours_5' => 6,
            'hours_6' => 7,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2025-11-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 1,
                'start' => '2025-11-01',
                'hours_0' => 1.0,
                'hours_1' => 2.0,
                'hours_2' => 3.0,
                'hours_3' => 4.3,
                'hours_4' => 5.0,
                'hours_5' => 6.0,
                'hours_6' => 7.0,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2020-02-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 1,
                'start' => '2020-02-01',
                'end' => '2025-10-31',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionStartNotFirstOfMonth(): void
    {
        $parameter = [
            'user_id' => '1', // req
            'start' => '2025-11-11', // req
            'hours_0' => 1,
            'hours_1' => 2,
            'hours_2' => 3,
            'hours_3' => 4.3,
            'hours_4' => 5,
            'hours_5' => 6,
            'hours_6' => 7,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2025-11-11')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 1,
                'start' => '2025-11-11',
                'hours_0' => 1.0,
                'hours_1' => 2.0,
                'hours_2' => 3.0,
                'hours_3' => 4.3,
                'hours_4' => 5.0,
                'hours_5' => 6.0,
                'hours_6' => 7.0,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '2020-02-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 1,
                'start' => '2020-02-01',
                'end' => '2025-11-10',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionAlterExistingContract(): void
    {
        $parameter = [
            'user_id' => '3', // req
            'start' => '0700-08-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // look at old contract
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '0700-01-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '0700-07-31',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractStartsDuringNewWithoutEnd(): void
    {
        $parameterContract1 = [
            'user_id' => '3', // req
            'start' => '2020-01-01', // req
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
            'user_id' => '3', // req
            'start' => '2019-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract2);
        $this->assertStatusCode(422);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractStartsDuringNew(): void
    {
        $parameterContract1 = [
            'user_id' => '3', // req
            'start' => '2020-01-01', // req
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
            'user_id' => '3', // req
            'start' => '2019-01-01', // req
            'end' => '2020-02-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract2);
        $this->assertStatusCode(422);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractWithoutEndStartsDuringNew(): void
    {
        $parameterContract1 = [
            'user_id' => '3', // req
            'start' => '2020-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', // req
            'start' => '2019-01-01', // req
            'end' => '2022-03-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract2);
        $this->assertStatusCode(422);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractWithoutEndStartsDuringNewWithoutEnd(): void
    {
        $parameterContract1 = [
            'user_id' => '3', // req
            'start' => '2020-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', // req
            'start' => '2019-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract2);
        $this->assertStatusCode(422);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractEndStartsDuringNewEndWithNewContractEndingAfterOld(): void
    {
        $parameterContract1 = [
            'user_id' => '3', // req
            'start' => '2020-01-01', // req
            'end' => '2022-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', // req
            'start' => '2019-01-01', // req
            'end' => '2027-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract2);
        $this->assertStatusCode(422);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Startdatum in der Zukunft, das sich mit dem neuen Vertrag überschneidet.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionNewContractStartsDuringOldWithEnd(): void
    {
        $parameterContract1 = [
            'user_id' => '3', // req
            'start' => '2020-01-01', // req
            'end' => '2022-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', // req
            'start' => '2021-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract2);
        $this->assertStatusCode(422);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionNewContractWithEndDuringOldStartsDuringOldWithEnd(): void
    {
        $parameterContract1 = [
            'user_id' => '3', // req
            'start' => '2020-01-01', // req
            'end' => '2024-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', // req
            'start' => '2021-01-01', // req
            'end' => '2022-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract2);
        $this->assertStatusCode(422);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionNewContractWithEndAfterOldStartsDuringOldWithEnd(): void
    {
        $parameterContract1 = [
            'user_id' => '3', // req
            'start' => '2020-01-01', // req
            'end' => '2024-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $parameterContract2 = [
            'user_id' => '3', // req
            'start' => '2021-01-01', // req
            'end' => '2030-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract1);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract2);
        $this->assertStatusCode(422);
        $this->assertMessage('Es besteht bereits ein laufender Vertrag mit einem Enddatum in der Zukunft.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractStartsInFutureAfterNewEnds(): void
    {
        $parameterContract = [
            'user_id' => '3', // req
            'start' => '500-01-01', // req
            'end' => '600-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '0700-01-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => null,
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractWithEndStartsInFutureAfterNewEnds(): void
    {
        $parameterContract = [
            'user_id' => '2', // req
            'start' => '500-01-01', // req
            'end' => '600-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01')
        ;
        $result = (method_exists($this->queryBuilder, 'executeQuery') ? $this->queryBuilder->executeQuery() : $this->queryBuilder->execute())->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractWithEndbeforeStartNewContract(): void
    {
        $parameterContract = [
            'user_id' => '2', // req
            'start' => '5000-01-01', // req
            'end' => '6000-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionUpdateOldContract(): void
    {
        $parameterContract = [
            'user_id' => '3', // req
            'start' => '5000-01-01', // req
            'end' => '6000-01-01',
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '700-01-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '4999-12-31',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionUpdateOldContractNewWithoutEnd(): void
    {
        $parameterContract = [
            'user_id' => '3', // req
            'start' => '5000-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '700-01-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 3,
                'start' => '0700-01-01',
                'end' => '4999-12-31',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionOldContractWithEndbeforeStartNewContractOpenEnd(): void
    {
        $parameterContract = [
            'user_id' => '2', // req
            'start' => '5000-01-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameterContract);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([5]);
        // test old contract not updated
        $this->queryBuilder
            ->select('*')
            ->from('contracts')
            ->where('start = ?')
            ->setParameter(0, '1020-01-01')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'user_id' => 2,
                'start' => '1020-01-01',
                'end' => '2020-01-01',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionMultipleOpenEndedContracts(): void
    {
        $values = [
            'user_id' => '3',
            'start' => "'2020-04-01'",
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
            ->executeStatement()
        ;

        $parameter = [
            'user_id' => '3', // req
            'start' => '2020-08-01', // req
            'hours_0' => 1,
            'hours_1' => 1,
            'hours_2' => 1,
            'hours_3' => 1,
            'hours_4' => 1,
            'hours_5' => 1,
            'hours_6' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $this->assertMessage('Für den Nutzer besteht mehr als ein unbefristeter Vertrag.');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testSaveContractActionDevNotAllowed(): void
    {
        $this->setInitialDbState('contracts');
        $this->logInSession('developer');
        $parameter = [
            'user_id' => '1', // req
            'start' => '2019-11-01', // req
            'hours_0' => 1,
            'hours_1' => 2,
            'hours_2' => 3,
            'hours_3' => 4.3,
            'hours_4' => 5,
            'hours_5' => 6,
            'hours_6' => 7,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('contracts');

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testUpdateContract(): void
    {
        $parameter = [
            'id' => 1,
            'user_id' => '3', // req
            'start' => '1000-01-01', // req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $this->assertJsonStructure([1]);
        // validate updated contract in db
        $this->queryBuilder->select('*')
            ->from('contracts')->where('id = ?')
            ->setParameter(0, 1)
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
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
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);

        // for some unknown reason the transaction is not rolled back
        $this->forceReset();
    }

    public function testCreateContractUserNotExist(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'user_id' => '42', // req
            'start' => '1000-01-01', // req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
    }

    public function testCreateContractNoEntry(): void
    {
        $parameter = [
            'id' => '100',
            'user_id' => '1', // req
            'start' => '1000-01-01', // req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $expectedJson = ['message' => 'No entry for id.'];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(404);
        $this->assertJsonStructure($expectedJson);
    }

    public function testCreateContractInvalidStartDate(): void
    {
        $parameter = [
            'user_id' => '1', // req
            'start' => 'test', // req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        try {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
            $this->assertStatusCode(422);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $unprocessableEntityHttpException) {
            self::assertSame(422, $unprocessableEntityHttpException->getStatusCode());
        }
    }

    public function testCreateContractGreaterStartThenEnd(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'user_id' => '1', // req
            'start' => '1000-01-01', // req
            'end' => '0900-01-01',
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $this->assertMessage('Das Vertragsende muss nach dem Vertragsbeginn liegen.');
    }

    public function testUpdateContractDevNotAllowed(): void
    {
        $this->setInitialDbState('contracts');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'user_id' => '2', // req
            'start' => '1000-01-01', // req
            'hours_0' => 0,
            'hours_1' => 0,
            'hours_2' => 0,
            'hours_3' => 0,
            'hours_4' => 0,
            'hours_5' => 0,
            'hours_6' => 0,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('contracts');
    }

    public function testDeleteContractAction(): void
    {
        $parameter = ['id' => 1];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteContractActionDevNotAllowed(): void
    {
        $this->setInitialDbState('contracts');
        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/contract/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('contracts');
    }

    public function testGetContractAction(): void
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

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getContracts');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    // -------------- ticketSystems routes ----------------------------------------
    public function testGetTicketSystemsAction(): void
    {
        $expectedJson = [
            0 => [
                'ticketSystem' => [
                    'name' => 'testSystem',
                ],
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getTicketSystems');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testSaveTicketSystemAction(): void
    {
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');
        $parameter = [
            'name' => 'testSaveTicketSystem', // req
            'url' => '',
            'type' => '',
            'ticketUrl' => '',
            'login' => '',
            'password' => '',
            'publicKey' => '',
            'privateKey' => '',
        ];
        $expectedJson = [
            'name' => 'testSaveTicketSystem',
        ];
        try {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
            $this->assertStatusCode(200);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            self::fail('Unexpected 422 for valid ticketsystem save');
        }

        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder
            ->select('*')
            ->from('ticket_systems')
            ->where('name = ?')
            ->setParameter(0, 'testSaveTicketSystem')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'name' => 'testSaveTicketSystem',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveTicketSystemActionDevNotAllowed(): void
    {
        $this->setInitialDbState('ticket_systems');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'testSaveTicketSystem', // req
            'url' => '',
            'type' => '',
            'ticketUrl' => '',
            'login' => '',
            'password' => '',
            'publicKey' => '',
            'privateKey' => '',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('ticket_systems');
    }

    public function testUpdateTicketSystem(): void
    {
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');
        $parameter = [
            'id' => 1,
            'name' => 'testSaveTicketSystemUpdate', // req
            'url' => '',
            'type' => '',
            'ticketUrl' => '',
            'login' => '',
            'password' => '',
            'publicKey' => '',
            'privateKey' => '',
        ];
        $expectedJson = [
            'name' => 'testSaveTicketSystemUpdate',
        ];
        try {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
            $this->assertStatusCode(200);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            self::fail('Unexpected 422 for valid ticketsystem update');
        }

        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder
            ->select('*')
            ->from('ticket_systems')
            ->where('name = ?')
            ->setParameter(0, 'testSaveTicketSystemUpdate')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'name' => 'testSaveTicketSystemUpdate',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdateTicketSystemDevNotAllowed(): void
    {
        $this->setInitialDbState('ticket_systems');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'testSaveTicketSystemUpdate', // req
            'url' => '',
            'type' => '',
            'ticketUrl' => '',
            'login' => '',
            'password' => '',
            'publicKey' => '',
            'privateKey' => '',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('ticket_systems');
    }

    public function testDeleteTicketSystemAction(): void
    {
        $parameter = ['id' => 1];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeleteTicketSystemActionDevNotAllowed(): void
    {
        $this->setInitialDbState('ticket_systems');
        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('ticket_systems');
    }

    // -------------- presets routes ----------------------------------------
    public function testGetPresetsAction(): void
    {
        $expectedJson = [
            0 => [
                'preset' => [
                    'id' => 1,
                    'name' => 'Urlaub',
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => 'Urlaub',
                ],
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllPresets');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testSavePresetAction(): void
    {
        $parameter = [
            'name' => 'newPreset', // req
            'customer' => 1, // req
            'project' => 1, // req
            'activity' => 1, // req
            'description' => '',    // req
        ];
        $expectedJson = [
            'name' => 'newPreset',
            'customer' => 1,
            'project' => 1,
            'activity' => 1,
            'description' => '',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/preset/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder
            ->select('*')
            ->from('presets')
            ->where('name = ?')
            ->setParameter(0, 'newPreset')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'name' => 'newPreset',
                'customer_id' => 1,
                'project_id' => 1,
                'activity_id' => 1,
                'description' => '',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testSavePresetActionDevNotAllowed(): void
    {
        $this->setInitialDbState('presets');
        $this->logInSession('developer');
        $parameter = [
            'name' => 'newPreset', // req
            'customer' => 1, // req
            'project' => 1, // req
            'activity' => 1, // req
            'description' => '',    // reg
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/preset/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('presets');
    }

    public function testUpdatePreset(): void
    {
        $parameter = [
            'id' => 1,
            'name' => 'newPresetUpdated', // req
            'customer' => 1, // req
            'project' => 1, // req
            'activity' => 1, // req
            'description' => '',    // reg
        ];
        $expectedJson = [
            'name' => 'newPresetUpdated',
            'customer' => 1,
            'project' => 1,
            'activity' => 1,
            'description' => '',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/preset/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder
            ->select('*')
            ->from('presets')
            ->where('name = ?')
            ->setParameter(0, 'newPresetUpdated')
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'name' => 'newPresetUpdated',
                'customer_id' => 1,
                'project_id' => 1,
                'activity_id' => 1,
                'description' => '',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testUpdatePresetDevNotAllowed(): void
    {
        $this->setInitialDbState('presets');
        $this->logInSession('developer');
        $parameter = [
            'id' => 1,
            'name' => 'newPresetUpdated', // req
            'customer' => 1, // req
            'project' => 1, // req
            'activity' => 1, // req
            'description' => '',    // reg
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/preset/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('presets');
    }

    public function testDeletePresetAction(): void
    {
        $parameter = ['id' => 1];
        $expectedJson1 = [
            'success' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/preset/delete', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson1);
        //  second delete
        $expectedJson2 = [
            'message' => 'Der Datensatz konnte nicht enfernt werden! ',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/preset/delete', $parameter);
        $this->assertStatusCode(422, 'Second delete did not return expected 422');
        $this->assertContentType('application/json');
        $this->assertJsonStructure($expectedJson2);
    }

    public function testDeletePresetActionDevNotAllowed(): void
    {
        $this->setInitialDbState('presets');
        $this->logInSession('developer');
        $parameter = ['id' => 1];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/preset/delete', $parameter);
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
        $this->assertDbState('presets');
    }

    public function testSyncProjectSubticketsAction(): void
    {
        $parameter = [
            'project' => 1,
        ];

        // Use the configured route from legacy routing
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/1/syncsubtickets', $parameter);

        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 400, 401], 'Status not in expected set');
    }

    public function testSyncAllProjectSubticketsAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/syncsubtickets');
        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 400, 401], 'Status not in expected set');
    }

    public function testJiraSyncEntriesAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/syncentries/jira', ['id' => 1]);
        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 400, 401], 'Status not in expected set');
    }
}
