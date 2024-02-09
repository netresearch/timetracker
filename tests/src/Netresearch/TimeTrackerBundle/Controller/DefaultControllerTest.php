<?php

namespace Tests\Netresearch\TimeTrackerBundle\Controller;

use Tests\BaseTest;

class DefaultControllerTest extends BaseTest
{
    /**
     * AdminController and DefaultController both have a function
     * with the name getCustomersAction()
     * To differentiate them we give this one the suffix Default
     */
    public function testGetCustomersActionDefault()
    {
        $expectedJson = [
            [
                'customer' => [
                    'name' => 'Der Bäcker von nebenan',
                ],
            ],
        ];
        $this->client->request('GET', '/getCustomers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetAllProjectsAction()
    {
        $parameter = [
            'customer' => 1,
        ];
        $expectedJson = [
            [
                'project' => [
                    'name' => 'Server attack',
                    'active' => true,
                    'customer' => 1,
                    'global' => false,
                    'jiraId' => 'SA',
                    'jira_id' => 'SA',
                    'subtickets' => [],
                    'ticketSystem' => null,
                    'ticket_system' => null,
                    'entries' => [],
                    'estimation' => 0,
                    'offer' => '0',
                    'billing' => 0,
                    'projectLead' => 1,
                    'project_lead' => 1,
                    'technicalLead' => 1,
                    'technical_lead' => 1,
                    'internalJiraTicketSystem' => 0,
                    'internal_jira_ticket_system' => 0,
                    'estimationText' => '0m',
                ],
            ],
            [
                'project' => [
                    'name' => 'Attack Server',
                    'active' => false,
                    'customer' => 1,
                    'global' => false,
                    'jiraId' => 'TIM-1',
                    'jira_id' => 'TIM-1',
                    'subtickets' => [],
                    'ticketSystem' => null,
                    'ticket_system' => null,
                    'entries' => [],
                    'estimation' => 0,
                    'offer' => '0',
                    'billing' => 0,
                    'projectLead' => null,
                    'project_lead' => null,
                    'technicalLead' => null,
                    'technical_lead' => null,
                    'internalJiraTicketSystem' => 0,
                    'internal_jira_ticket_system' => 0,
                    'estimationText' => '0m',
                ],
            ]
        ];
        $notExpectedJson = [];
        $this->client->request('GET', '/getAllProjects', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->assertLength(2); //2 Projects for customer 1 in Database
    }

    /**
     * Without parameter customer, the response will contain
     *  all project belonging to the
     * customer belonging to the teams of the current user +
     * all projects of global customers
     *
     * With a customer the response contains from the
     * above projects the ones with global project
     * status + the one belonging to the customer
     *
     *
     */
    public function testGetProjectsAction()
    {
        $parameter = [
            'customer' => 3,
        ];
        $expectedJson = [
            [
                'project' => [
                    'name' => 'GlobalProject',
                    'active' => false,
                    'customer' => 3,
                    'global' => false,
                    'jiraId' => 'TIM-1',
                    'jira_id' => 'TIM-1',
                    'subtickets' => [],
                    'ticketSystem' => null,
                    'ticket_system' => null,
                    'entries' => [],
                    'estimation' => 0,
                    'offer' => '0',
                    'billing' => 0,
                    'projectLead' => null,
                    'project_lead' => null,
                    'technicalLead' => null,
                    'technical_lead' => null,
                    'internalJiraTicketSystem' => 0,
                    'internal_jira_ticket_system' => 0,
                    'estimationText' => '0m',
                ],
            ],
        ];
        $this->client->request('GET', '/getProjects', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->assertLength(1);
    }

    public function testGetProjectStructureAction()
    {
        $expectedJson = array(
            1 => array(
                0 => array(
                    'id' => 2,
                    'name' => 'Attack Server',
                    'jiraId' => 'TIM-1',
                    'active' => false,
                ),
                1 => array(
                    'id' => 1,
                    'name' => 'Server attack',
                    'jiraId' => 'SA',
                    'active' => true,
                ),
            ),
            3 => array(
                0 => array(
                    'id' => 3,
                    'name' => 'GlobalProject',
                    'jiraId' => 'TIM-1',
                    'active' => false,
                ),
            ),
            'all' => array(
                0 => array(
                    'id' => 2,
                    'name' => 'Attack Server',
                    'active' => false,
                    'customer' => 1,
                    'global' => false,
                    'jiraId' => 'TIM-1',
                    'jira_id' => 'TIM-1',
                    'subtickets' => array(),
                    'entries' => array(),
                    'projectLead' => null,
                    'project_lead' => null,
                    'technicalLead' => null,
                    'technical_lead' => null,
                ),
                1 => array(
                    'id' => 3,
                    'name' => 'GlobalProject',
                    'active' => false,
                    'customer' => 3,
                    'global' => false,
                    'jiraId' => 'TIM-1',
                    'jira_id' => 'TIM-1',
                    'subtickets' => array(),
                    'entries' => array(),
                    'projectLead' => null,
                    'project_lead' => null,
                    'technicalLead' => null,
                    'technical_lead' => null,
                ),
                2 => array(
                    'id' => 1,
                    'name' => 'Server attack',
                    'active' => true,
                    'customer' => 1,
                    'global' => false,
                    'jiraId' => 'SA',
                    'jira_id' => 'SA',
                    'subtickets' => array(),
                    'entries' => array(),
                    'projectLead' => 1,
                    'project_lead' => 1,
                    'technicalLead' => 1,
                    'technical_lead' => 1,
                ),
            ),
        );
        $this->client->request('GET', '/getProjectStructure');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    //-------------- activities routes ----------------------------------------
    public function testGetActivitiesAction()
    {
        $expectedJson = array(
            0 => array(
                'activity' => array(
                    'id' => 1,
                    'name' => 'Backen',
                    'needsTicket' => false,
                    'factor' => 1,
                ),
            ),
        );

        $this->client->request('GET', '/getActivities');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }
}
