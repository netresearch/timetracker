<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class AdminControllerTest extends AbstractWebTestCase
{
    // -------------- customers routes --------------------------------
    public function testNewCustomerActionWithWrongMethod(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class);
        $this->client->request('GET', '/customer/save');
    }

    public function testNewCustomerActionWithPL(): void
    {
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 0, // 0 means new customer
            'name' => 'customerName',
            'active' => true,
            'global' => false,
            'teams' => [1], // Assign to team 1
        ]));
        $this->assertStatusCode(200);
    }

    public function testNewCustomerActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 0, // 0 means new customer
            'name' => 'customerName',
            'active' => true,
            'global' => false,
            'teams' => [1], // Assign to team 1
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testEditCustomerActionWithPL(): void
    {
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Existing customer ID
            'name' => 'customerName Updated',
            'active' => true,
            'global' => false,
            'teams' => [1], // Keep assigned to team 1
        ]));
        $this->assertStatusCode(200);
    }

    public function testEditCustomerActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Existing customer ID
            'name' => 'customerName Updated',
            'active' => true,
            'global' => false,
            'teams' => [1], // Keep assigned to team 1
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testGetCustomersAction(): void
    {
        // Updated to use admin route and match all customers ordered by name ASC
        $expectedJson = [
            [
                'customer' => [
                    'id' => 1,
                    'name' => 'Der Bäcker von nebenan',
                    'active' => true,
                    'global' => false,
                    'teams' => [1], // Customer 1 is associated with team 1
                ],
            ],
            [
                'customer' => [
                    'id' => 3,
                    'name' => 'Der Globale Customer',
                    'active' => true,
                    'global' => true,
                    'teams' => [], // Global customer has no specific teams
                ],
            ],
            [
                'customer' => [
                    'id' => 2,
                    'name' => 'Der nebenan vom Bäcker',
                    'active' => false,
                    'global' => false,
                    'teams' => [2], // Customer 2 is associated with team 2
                ],
            ],
        ];

        // Use admin route that returns all customers with full data
        $this->client->request('GET', '/getAllCustomers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetCustomersActionWithNonPL(): void
    {
        // Note: /getAllCustomers only requires login, not PL role
        // So developer users can also access this route
        $this->logInSession('developer');
        $this->client->request('GET', '/getAllCustomers');
        $this->assertStatusCode(200);
        // Should return same structure as PL user
        $this->assertJsonStructure([
            [
                'customer' => [
                    'id' => 1,
                    'name' => 'Der Bäcker von nebenan',
                    'active' => true,
                    'global' => false,
                    'teams' => [1],
                ],
            ]
        ]);
    }

    public function testDeleteCustomerActionWithPL(): void
    {
        $this->client->request('POST', '/customer/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2,
        ]));
        $this->assertStatusCode(200);
    }

    public function testDeleteCustomerActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/customer/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2,
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    // -------------- projects routes ----------------------------------
    public function testNewProjectAction(): void
    {
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 0, // 0 means new project
            'customer' => 1,
            'name' => 'newProject',
            'active' => true,
            'global' => false,
            'jiraId' => 'TEST',
        ]));
        $this->assertStatusCode(200);
    }

    public function testNewProjectActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 0, // 0 means new project
            'customer' => 1,
            'name' => 'newProject',
            'active' => true,
            'global' => false,
            'jiraId' => 'TEST',
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testEditProjectAction(): void
    {
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Existing project ID
            'customer' => 1,
            'name' => 'editedProject',
            'active' => true,
            'global' => false,
            'jiraId' => 'TEST',
        ]));
        $this->assertStatusCode(200);
    }

    public function testEditProjectActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Existing project ID
            'customer' => 1,
            'name' => 'editedProject',
            'active' => true,
            'global' => false,
            'jiraId' => 'TEST',
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testGetProjectsAction(): void
    {
        $expectedJson = [
            [
                'id' => 2,
                'name' => 'Attack Server',
                'customerId' => 1,
                'customerName' => 'Der Bäcker von nebenan',
            ],
            [
                'id' => 1,
                'name' => 'Das Kuchenbacken',
                'customerId' => 1,
                'customerName' => 'Der Bäcker von nebenan',
            ],
            [
                'id' => 3,
                'name' => 'GlobalProject',
                'customerId' => 3,
                'customerName' => 'Der Globale Customer',
            ],
        ];

        $this->client->request('GET', '/getProjects');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetProjectsActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('GET', '/getProjects');
        $this->assertMessage('You are not allowed to perform this action.');
    }

    // -------------- users routes --------------------------------
    /**
     * Returns all Users for dev and non dev
     * unique feature = returns teams for user.
     */
    public function testGetUsersAction(): void
    {
        // Updated to match all 5 users in test data, ordered alphabetically by username
        $expectedJson = [
            0 => [
                'user' => [
                    'id' => 2,
                    'username' => 'developer',
                    'type' => 'DEV',
                    'abbr' => 'NPL',
                    'locale' => 'de',
                    'teams' => [2], // User 2 is in team 2
                ],
            ],
            1 => [
                'user' => [
                    'id' => 3,
                    'username' => 'i.myself',
                    'type' => 'PL',
                    'abbr' => 'IMY',
                    'locale' => 'de',
                    'teams' => [], // User 3 has no teams in current test data
                ],
            ],
            2 => [
                'user' => [
                    'id' => 5,
                    'username' => 'noContract',
                    'type' => 'PL',
                    'abbr' => 'NCO',
                    'locale' => 'de',
                    'teams' => [], // User 5 has no teams
                ],
            ],
            3 => [
                'user' => [
                    'id' => 4,
                    'username' => 'testGroupByActionUser',
                    'type' => 'DEV',
                    'abbr' => 'NPL',
                    'locale' => 'de',
                    'teams' => [], // User 4 has no teams
                ],
            ],
            4 => [
                'user' => [
                    'id' => 1,
                    'username' => 'unittest',
                    'type' => 'PL',
                    'abbr' => 'UTE',
                    'locale' => 'de',
                    'teams' => [1], // User 1 is in team 1
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
                    'id' => 2,
                    'name' => 'Hackerman',
                    'lead_user_id' => 2,
                ],
            ],
            [
                'team' => [
                    'id' => 1,
                    'name' => 'Kuchenbäcker',
                    'lead_user_id' => 1,
                ],
            ],
        ];

        $this->client->request('GET', '/getAllTeams');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetTeamsActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('GET', '/getAllTeams');
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testNewTeamActionWithPL(): void
    {
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 0, // 0 means new team
            'name' => 'teamName',
            'lead_user_id' => 1,
        ]));
        $this->assertStatusCode(200);
    }

    public function testNewTeamActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 0, // 0 means new team
            'name' => 'teamName',
            'lead_user_id' => 1,
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testEditTeamActionWithPL(): void
    {
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Existing team ID
            'name' => 'editedTeamName',
            'lead_user_id' => 1,
        ]));
        $this->assertStatusCode(200);
    }

    public function testEditTeamActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Existing team ID
            'name' => 'editedTeamName',
            'lead_user_id' => 1,
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testDeleteTeamActionWithPL(): void
    {
        $this->client->request('POST', '/team/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2,
        ]));
        $this->assertStatusCode(200);
    }

    public function testDeleteTeamActionWithNonPL(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/team/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2,
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }
}