<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class ApiSmokeTest extends AbstractWebTestCase
{
    public function testGetActivities(): void
    {
        ->client->request('GET', '/getActivities');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
            ->assertArrayHasKey('activity', [0]);
        }
    }

    public function testGetAllCustomers(): void
    {
        ->client->request('GET', '/getAllCustomers');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
            ->assertArrayHasKey('customer', [0]);
        }
    }

    public function testGetAllProjects(): void
    {
        ->client->request('GET', '/getAllProjects');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
            ->assertArrayHasKey('project', [0]);
        }
    }

    public function testGetAllTeams(): void
    {
        ->client->request('GET', '/getAllTeams');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
            ->assertArrayHasKey('team', [0]);
        }
    }

    public function testGetAllUsers(): void
    {
        ->client->request('GET', '/getAllUsers');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
            ->assertArrayHasKey('user', [0]);
        }
    }

    public function testGetContracts(): void
    {
        ->client->request('GET', '/getContracts');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
            ->assertArrayHasKey('contract', [0]);
        }
    }

    public function testGetCustomers(): void
    {
        ->client->request('GET', '/getCustomers');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
    }

    public function testGetData(): void
    {
        ->client->request('GET', '/getData');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
    }

    public function testGetDataDays(): void
    {
        ->client->request('GET', '/getData/days/3');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
    }

    public function testGetTicketSystems(): void
    {
        ->client->request('GET', '/getTicketSystems');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
    }

    public function testGetTimeSummary(): void
    {
        ->client->request('GET', '/getTimeSummary');
        ->assertStatusCode(200);
         = json_decode((string) ->client->getResponse()->getContent(), true);
        ->assertIsArray();
        ->assertArrayHasKey('today', );
        ->assertArrayHasKey('week', );
        ->assertArrayHasKey('month', );
    }

    public function testGetTicketTimeSummary(): void
    {
        ->client->request('GET', '/getTicketTimeSummary/TIM-1');
         = ->client->getResponse()->getStatusCode();
        ->assertContains(, [200, 404]);
    }

    public function testInterpretationEndpoints(): void
    {
         = [
            '/interpretation/activity',
            '/interpretation/customer',
            '/interpretation/project',
            '/interpretation/ticket',
            '/interpretation/user',
            '/interpretation/time',
            '/interpretation/entries',
        ];
        foreach ( as ) {
            ->client->request('GET', );
             = ->client->getResponse()->getStatusCode();
            ->assertContains(, [200, 406]);
        }
    }

    public function testStatusCheckAndPage(): void
    {
        ->client->request('GET', '/status/check');
        ->assertStatusCode(200);
        ->client->request('GET', '/status/page');
        ->assertStatusCode(200);
    }
}
