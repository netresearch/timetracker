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

    public function testNewCustomerActionWithPl(): void
    {
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 0, // 0 means new customer
            'name' => 'customerName',
            'active' => true,
            'global' => false,
            'teams' => [1], // Assign to team 1
        ]));
        $this->assertStatusCode(200);
    }

    public function testNewCustomerActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 0, // 0 means new customer
            'name' => 'customerName',
            'active' => true,
            'global' => false,
            'teams' => [1], // Assign to team 1
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testEditCustomerActionWithPl(): void
    {
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 1, // Existing customer ID
            'name' => 'customerName Updated',
            'active' => true,
            'global' => false,
            'teams' => [1], // Keep assigned to team 1
        ]));
        $this->assertStatusCode(200);
    }

    public function testEditCustomerActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
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
        self::assertEquals($expectedJson, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetCustomersActionWithNonPl(): void
    {
        // /getAllCustomers now requires ROLE_ADMIN after auth modernization
        $this->logInSession('developer');
        $this->client->request('GET', '/getAllCustomers');
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testDeleteCustomerActionWithPl(): void
    {
        $this->client->request('POST', '/customer/delete', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 2,
        ]));
        $this->assertStatusCode(200);
    }

    public function testDeleteCustomerActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/customer/delete', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 2,
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    // -------------- projects routes ----------------------------------
    public function testNewProjectAction(): void
    {
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 0, // 0 means new project
            'customer' => 1,
            'name' => 'newProject',
            'active' => true,
            'global' => false,
            'jiraId' => 'TEST',
        ]));
        $this->assertStatusCode(200);
    }

    public function testNewProjectActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
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
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 1, // Existing project ID
            'customer' => 1,
            'name' => 'editedProject',
            'active' => true,
            'global' => false,
            'jiraId' => 'TEST',
        ]));
        $this->assertStatusCode(200);
    }

    public function testEditProjectActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
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
        self::assertEquals($expectedJson, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetProjectsActionWithNonPl(): void
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
                    'type' => 'ADMIN',
                    'abbr' => 'IMY',
                    'locale' => 'de',
                    'teams' => [], // User 3 has no teams in current test data
                ],
            ],
            2 => [
                'user' => [
                    'id' => 5,
                    'username' => 'noContract',
                    'type' => 'ADMIN',
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
                    'type' => 'ADMIN',
                    'abbr' => 'UTE',
                    'locale' => 'de',
                    'teams' => [1], // User 1 is in team 1
                ],
            ],
        ];

        // Make the request - should work with our authentication from setUp
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllUsers');

        // Assert response status and expected JSON
        $this->assertStatusCode(200);
        self::assertEquals($expectedJson, $this->getJsonResponse($this->client->getResponse()));
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
        self::assertEquals($expectedJson, $this->getJsonResponse($this->client->getResponse()));
    }

    public function testGetTeamsActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('GET', '/getAllTeams');
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testNewTeamActionWithPl(): void
    {
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 0, // 0 means new team
            'name' => 'teamName',
            'lead_user_id' => 1,
        ]));
        $this->assertStatusCode(200);
    }

    public function testNewTeamActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 0, // 0 means new team
            'name' => 'teamName',
            'lead_user_id' => 1,
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testEditTeamActionWithPl(): void
    {
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 1, // Existing team ID
            'name' => 'editedTeamName',
            'lead_user_id' => 1,
        ]));
        $this->assertStatusCode(200);
    }

    public function testEditTeamActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 1, // Existing team ID
            'name' => 'editedTeamName',
            'lead_user_id' => 1,
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }

    public function testDeleteTeamActionWithPl(): void
    {
        $this->client->request('POST', '/team/delete', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 2,
        ]));
        $this->assertStatusCode(200);
    }

    public function testDeleteTeamActionWithNonPl(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/team/delete', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'id' => 2,
        ]));
        $this->assertMessage('You are not allowed to perform this action.');
    }
}
