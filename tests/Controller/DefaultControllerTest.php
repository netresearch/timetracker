<?php

namespace Tests\Controller;

use Tests\Base;

class DefaultControllerTest extends Base
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

    //-------------- users routes ----------------------------------------
    /**
     * Returns all users
     */
    public function testGetUsersAction()
    {
        $expectedJson = array(
            0 => array(
                'user' => array(
                    'username' => 'i.myself',
                    'type' => 'PL',
                    'abbr' => 'IMY',
                    'locale' => 'de',
                ),
            ),
            1 => array(
                'user' => array(
                    'username' => 'developer',
                    'type' => 'DEV',
                    'abbr' => 'NPL',
                    'locale' => 'de',
                ),
            ),
        );
        $this->client->request('GET', '/getUsers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    /**
     * Returns the user logged in seassion
     */
    public function testGetUsersActionDev()
    {
        $expectedJson = array(
            0 => array(
                'user' => array(
                    'username' => 'developer',
                    'type' => 'DEV',
                    'abbr' => 'NPL',
                    'locale' => 'de',
                ),
            ),
        );
        $this->logInSession('developer');
        $this->client->request('GET', '/getUsers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    //-------------- data routes ----------------------------------------
    public function testGetDataActionDefaultParameter()
    {
        $expectedJson = array(
            0 => array(
                'entry' => array(
                    'date' => date('d/m/Y'),
                    'start' => '13:00',
                    'end' => '13:25',
                    'user' => 1,
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => 'testGetDataAction',
                    'ticket' => 'testGetDataAction',
                    'class' => 1,
                    'duration' => '00:25',
                ),
            ),
            1 => array(
                'entry' => array(
                    'date' => date('d/m/Y', strtotime('-3 days')),
                    'start' => '14:00',
                    'end' => '14:25',
                    'user' => 1,
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => 'testGetDataAction',
                    'ticket' => 'testGetDataAction',
                    'class' => 1,
                    'duration' => '00:25',
                ),
            ),
        );
        $this->client->request('GET', '/getData');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetDataActionForParameter()
    {
        $parameter = [
            'days' => 1,
        ];
        $expectedJson = array(
            0 => array(
                'entry' => array(
                    'date' => date('d/m/Y'),
                    'start' => '13:00',
                    'end' => '13:25',
                    'user' => 1,
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => 'testGetDataAction',
                    'ticket' => 'testGetDataAction',
                    'class' => 1,
                    'duration' => '00:25',
                ),
            ),
        );
        $this->client->request('GET', '/getData/days/' . $parameter['days']);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);

        // because route skips non workdays
        // 1 translates to , today and the last workday
        if (date('N') == 1) {
            $this->assertLength(2);
        } else {
            $this->assertLength(1);
        }
    }

    //-------------- summary routes ----------------------------------------
    public function testGetSummaryAction()
    {
        $parameter = [
            'id' => 1,  //req
        ];
        $expectedJson = array(
            'customer' => array(
                'scope' => 'customer',
                'name' => 'Der Bäcker von nebenan',
                'entries' => 7,
                'total' => '354',
                'own' => '284',
                'estimation' => 0,
            ),
            'project' => array(
                'scope' => 'project',
                'name' => 'Server attack',
                'entries' => 7,
                'total' => '354',
                'own' => '284',
                'estimation' => 0,
            ),
            'activity' => array(
                'scope' => 'activity',
                'name' => 'Backen',
                'entries' => 7,
                'total' => '354',
                'own' => '284',
                'estimation' => 0,
            ),
            'ticket' => array(
                'scope' => 'ticket',
                'name' => 'testGetLastEntriesAction',
                'entries' => 2,
                'total' => '220',
                'own' => '220',
                'estimation' => 0,
            ),
        );
        $this->client->request('POST', '/getSummary', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetSummaryIncorrectIdAction()
    {
        // test for non existent id
        $parameter = [
            'id' => 999,  //req
        ];
        $this->client->request('POST', '/getSummary', $parameter);
        $this->assertStatusCode(404, 'Second delete did not return expected 404');
        $this->assertJsonStructure(['message' => 'No entry for id.']);
    }

    public function testGetTimeSummaryAction()
    {
        $expectedJson = array(
            'today' => array(),
            'week' => array(),
            'month' => array(),
        );
        $this->client->request('GET', '/getTimeSummary');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        // assert that the duration is greater 0
        $result = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertGreaterThan(0, $result['today']['duration']);
        $this->assertGreaterThan(0, $result['week']['duration']);
        $this->assertGreaterThan(0, $result['month']['duration']);
    }
}
