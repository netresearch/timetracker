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
        $this->client->request('POST', '/customer/save', $parameter);
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
        $this->client->request('POST', '/project/save', $parameter);
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
        $this->client->request('POST', '/team/save', $parameter);
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
        $this->client->request('POST', '/activity/save', $parameter);
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
        $this->client->request('POST', '/ticketsystem/save', $parameter);
        $this->assertStatusCode(406);
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }
}
