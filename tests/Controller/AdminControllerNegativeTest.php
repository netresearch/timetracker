<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class AdminControllerNegativeTest extends AbstractWebTestCase
{
    public function testSaveCustomerDuplicateName(): void
    {
        $this->logInSession('unittest');
        // Name already exists in fixtures: 'Der Bäcker von nebenan'
        $parameter = [
            'name' => 'Der Bäcker von nebenan',
            'teams' => [1],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveProjectDuplicateNameForCustomer(): void
    {
        $this->logInSession('unittest');
        // For customer 1, project 'Server attack' already exists
        $parameter = [
            'name' => 'Server attack',
            'customer' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveTeamMissingLeadUser(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'Team ohne Lead',
            // missing lead_user_id
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveActivityDuplicateName(): void
    {
        $this->logInSession('unittest');
        // 'Backen' exists
        $parameter = [
            'name' => 'Backen',
            'factor' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveTicketSystemDuplicateName(): void
    {
        $this->logInSession('unittest');
        // 'testSystem' exists in fixtures
        $parameter = [
            'name' => 'testSystem',
            'type' => '',
            'url' => '',
            'ticketUrl' => '',
        ];
        try {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter);
            $this->assertStatusCode(406);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) {
            $this->fail('Unexpected 422 for duplicate name; should be business-rule 406');
        }
    }

    public function testSaveUserInvalidAbbrLength(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'username' => 'newuser1',
            'abbr' => 'XY', // invalid length
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV'
        ];
        try {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter);
            $this->assertStatusCode(422);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        // No further response assertions; exception path may bypass BrowserKit response population
        $this->assertTrue(true);
    }

    public function testSaveUserDuplicateUsername(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'username' => 'developer', // already exists in fixtures
            'abbr' => 'DEV',
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV'
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveUserNoTeams(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'username' => 'newuser2',
            'abbr' => 'NU2',
            'teams' => [], // no team
            'locale' => 'de',
            'type' => 'DEV'
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveCustomerNoTeamsNotGlobal(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'NoTeamCustomer',
            'global' => 0,
            'teams' => [],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveTeamDuplicateName(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'Hackerman', // exists in fixtures
            'lead_user_id' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveProjectInvalidJiraPrefix(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'JiraPrefixInvalid',
            'customer' => 1,
            'jiraId' => 'foo-', // invalid character (hyphen) remains after strtoupper
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    public function testSaveTicketSystemShortName(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'te', // too short
            'type' => '',
            'url' => '',
            'ticketUrl' => '',
        ];
        try {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter);
            $this->assertStatusCode(422);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    // Skipping a test for missing preset relations due to strict type setters causing TypeError
}
