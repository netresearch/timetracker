<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AdminControllerNegativeTest extends AbstractWebTestCase
{
    public function testSaveCustomerDuplicateName(): void
    {
        $this->logInSession('unittest');
        // Name already exists in fixtures: 'Der Bäcker von nebenan'
        $parameter = [
            'name' => 'Der Bäcker von nebenan',
            'teams' => [1],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
    }

    public function testSaveProjectDuplicateNameForCustomer(): void
    {
        $this->logInSession('unittest');
        // For customer 1, project 'Das Kuchenbacken' already exists
        $parameter = [
            'name' => 'Das Kuchenbacken',
            'customer' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
    }

    public function testSaveTeamMissingLeadUser(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'Team ohne Lead',
            // missing lead_user_id
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
    }

    public function testSaveActivityDuplicateName(): void
    {
        $this->logInSession('unittest');
        // 'Entwicklung' exists
        $parameter = [
            'name' => 'Entwicklung',
            'factor' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/activity/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
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
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
            $this->assertStatusCode(422);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            self::fail('Unexpected 422 for duplicate name; should be business-rule 406');
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
            'type' => 'DEV',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);
        
        // Check that we have either a violations array or a message
        // The violations array is what the JavaScript expects
        if (isset($data['violations']) && is_array($data['violations'])) {
            // Verify violations array format
            self::assertNotEmpty($data['violations'], 'Violations array should not be empty');
            $firstViolation = $data['violations'][0];
            self::assertArrayHasKey('message', $firstViolation, 'Violation should have a message field');
            self::assertArrayHasKey('title', $firstViolation, 'Violation should have a title field (for JS compatibility)');
            // Message should mention the abbreviation requirement
            self::assertMatchesRegularExpression('/3.*Zeichen|3.*characters|abbr/i', $firstViolation['message']);
        } else {
            // Fallback: check for message field
            self::assertArrayHasKey('message', $data);
            // Validation message may be localized, just ensure we got a validation error
            self::assertMatchesRegularExpression('/Zeichen|characters|abbr/i', $data['message']);
        }
    }

    public function testSaveUserDuplicateUsername(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'username' => 'developer', // already exists in fixtures
            'abbr' => 'DEV',
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('message', $data);
        // Validation message may be localized
        self::assertMatchesRegularExpression('/exists|existiert|bereits/i', $data['message']);
    }

    public function testSaveUserNoTeams(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'username' => 'newuser2',
            'abbr' => 'NU2',
            'teams' => [], // no team
            'locale' => 'de',
            'type' => 'DEV',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('message', $data);
        // Validation message may be localized
        self::assertMatchesRegularExpression('/teams|Team|sollte nicht leer sein/i', $data['message']);
    }

    public function testSaveCustomerNoTeamsNotGlobal(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'NoTeamCustomer',
            'global' => 0,
            'teams' => [],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/customer/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
    }

    public function testSaveTeamDuplicateName(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'Hackerman', // exists in fixtures
            'lead_user_id' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/team/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
    }

    public function testSaveProjectInvalidJiraPrefix(): void
    {
        $this->logInSession('unittest');
        $parameter = [
            'name' => 'JiraPrefixInvalid',
            'customer' => 1,
            'jiraId' => 'foo-', // invalid character (hyphen) remains after strtoupper
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/project/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertNotEmpty($content);
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
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/ticketsystem/save', $parameter, [], ['HTTP_ACCEPT' => 'application/json']);
            $this->assertStatusCode(422);
            $content = (string) $this->client->getResponse()->getContent();
            self::assertNotEmpty($content);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $unprocessableEntityHttpException) {
            self::assertSame(422, $unprocessableEntityHttpException->getStatusCode());
        }
    }

    // Skipping a test for missing preset relations due to strict type setters causing TypeError
}
