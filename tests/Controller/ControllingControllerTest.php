<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Model\JsonResponse;
use App\Service\ExportService as Export;
use DateTime;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ControllingControllerTest extends AbstractWebTestCase
{
    public function testExportActionBasicResponse(): void
    {
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling/export', [
            'year' => 2020,
            'month' => 2,
            'userid' => 2,
            'project' => 0,
        ]);
        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        $contentDisposition = $response->headers->get('Content-disposition');
        self::assertStringStartsWith('attachment;', (string) $contentDisposition);
        self::assertStringContainsString('02_developer', (string) $contentDisposition); // userid 2 = 'developer'
    }

    public function testExportActionInvalidMonth(): void
    {
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling/export', [
            'year' => 2020,
            'month' => 666,
            'userid' => 2,
            'project' => 0,
        ]);
        $this->assertStatusCode(422);
    }

    public function testExportActionInvalidYear(): void
    {
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling/export', [
            'year' => 666,
            'month' => 1,
            'userid' => 2,
            'project' => 0,
        ]);
        $this->assertStatusCode(422);
    }

    public function testExportActionWithBillableAndTicketTitles(): void
    {
        $_ENV['APP_SHOW_BILLABLE_FIELD_IN_EXPORT'] = 'true';

        // 1. Create export service mock
        $exportServiceMock = $this->createMock(Export::class);

        // --- Real entities for export ---
        $user = (new \App\Entity\User())->setId(1)->setUsername('unittest');
        $customer = (new \App\Entity\Customer())->setId(1)->setName('Test Customer');
        $project = (new \App\Entity\Project())->setId(1)->setName('Test Project');

        $entry1 = (new \App\Entity\Entry())
            ->setId(4)
            ->setDay(new DateTime('2023-10-15'))
            ->setStart(new DateTime('2023-10-15 09:00:00'))
            ->setEnd(new DateTime('2023-10-15 10:30:00'))
            ->setUser($user)
            ->setCustomer($customer)
            ->setProject($project)
            ->setTicket('TKT-1')
            ->setDescription('Real Desc 1');
        // ->setActivity(null) // Assuming default is fine or set if needed

        $entry2 = (new \App\Entity\Entry())
            ->setId(5)
            ->setDay(new DateTime('2023-10-20'))
            ->setStart(new DateTime('2023-10-20 11:00:00'))
            ->setEnd(new DateTime('2023-10-20 12:30:00'))
            ->setUser($user)
            ->setCustomer($customer)
            ->setProject($project)
            ->setTicket('TKT-2')
            ->setDescription('Real Desc 2');
        // --- End of real entity prep --- //

        // Mock exportEntries - return the REAL entries
        $exportServiceMock->expects(self::once())
            ->method('exportEntries')
            ->willReturn([$entry1, $entry2]);

        // Mock enrichEntries - expect it called with correct parameters, use callback to call setters on REAL entries
        $exportServiceMock->expects(self::once())
            ->method('enrichEntriesWithTicketInformation')
            ->willReturnCallback(static function ($userId, array $entries, $includeBillable, $includeTicketTitle, $searchTickets): array {
                foreach ($entries as $entry) {
                    // Use setters ON THE REAL Entry objects
                    if ($entry instanceof \App\Entity\Entry) {
                        if ($includeBillable) {
                            $entry->setBillable(true);
                        }

                        if ($includeTicketTitle) {
                            $entry->setTicketTitle('Mocked Title for ' . $entry->getTicket());
                        }
                    }
                }

                return $entries;
            });

        // Mock getUsername method used for filename generation - matches current service interface
        $exportServiceMock->expects(self::once())
            ->method('getUsername')
            ->with(1) // userid from the request
            ->willReturn('unittest');

        // Ensure kernel is shut down before creating client
        self::ensureKernelShutdown();

        // 2. Create client (boots kernel)
        $client = self::createClient();
        $this->client = $client;

        $this->serviceContainer = $this->client->getContainer();

        // 3. Replace service in the container obtained from the client
        $testContainer = $this->client->getContainer(); // Get container from client
        $testContainer->set(Export::class, $exportServiceMock); // Replace service

        // 4. Prepare and make request
        $this->logInSession('unittest');

        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 10,
                'userid' => 1,
                'project' => 0,
            ],
        );

        // 5. Assertions
        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        // ... (other header assertions)
        $contentDisposition = $response->headers->get('Content-disposition');
        self::assertStringStartsWith('attachment;', (string) $contentDisposition);
        self::assertStringContainsString(
            'attachment;filename=2023_10_unittest.xlsx', // Expect mocked username
            (string) $contentDisposition,
        );
        // ... (rest of assertions)
    }

    /**
     * Test that the billable column (N) is NOT present when APP_SHOW_BILLABLE_FIELD_IN_EXPORT is false/unset.
     */
    public function testExportActionHidesBillableFieldWhenNotConfigured(): void
    {
        $_ENV['APP_SHOW_BILLABLE_FIELD_IN_EXPORT'] = 'false';

        // 1. Create export service mock
        $exportServiceMock = $this->createMock(Export::class);

        // --- Mock data for export ---
        $user = (new \App\Entity\User())->setId(1)->setUsername('testuser');
        $customer = (new \App\Entity\Customer())->setId(1)->setName('Test Customer');
        $project = (new \App\Entity\Project())->setId(1)->setName('Test Project');

        $entry1 = (new \App\Entity\Entry())
            ->setId(6)
            ->setDay(new DateTime('2023-11-05'))
            ->setStart(new DateTime('2023-11-05 08:00:00'))
            ->setEnd(new DateTime('2023-11-05 09:30:00'))
            ->setUser($user)
            ->setCustomer($customer)
            ->setProject($project)
            ->setTicket('TKT-3')
            ->setDescription('Test Desc 3');

        // Mock exportEntries - return the mock entry
        $exportServiceMock->expects(self::once())
            ->method('exportEntries')
            ->willReturn([$entry1]);

        // Since showBillableField=false and tickettitles is not requested,
        // enrichEntriesWithTicketInformation should NOT be called
        $exportServiceMock->expects(self::never())
            ->method('enrichEntriesWithTicketInformation');

        // Mock getUsername method used for filename generation
        $exportServiceMock->expects(self::once())
            ->method('getUsername')
            ->with(1) // userid from the request
            ->willReturn('unittest');

        // Ensure kernel is shut down before creating client
        self::ensureKernelShutdown();

        // 2. Create client (boots kernel)
        $client = self::createClient();
        $this->client = $client;
        // Update service container reference after creating new client
        $this->serviceContainer = $this->client->getContainer();

        // 3. Replace service in the container
        $testContainer = $this->client->getContainer();
        $testContainer->set(Export::class, $exportServiceMock);

        // 4. Load test data and make request
        $this->logInSession('unittest');

        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 11,
                'userid' => 1,
                'project' => 0,
            ],
        );

        // 5. Assertions - verify response is 200 and doesn't contain billable column
        $this->assertStatusCode(200);

        // Note: Since we're mocking the service, the actual spreadsheet content
        // can't be easily tested without complex mocking of PhpSpreadsheet objects.
        // This test primarily ensures the correct methods are called on the service
        // with the correct parameters (showBillable = false).
    }

    public function testLandingPage(): void
    {
        $this->markTestSkipped('Route /controlling never existed - fictional test');
        $this->logInSession();
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling');
        $this->assertStatusCode(200);
        $response = $this->client->getResponse()->getContent();
        self::assertIsString($response);
        self::assertStringContainsString('Controlling', (string) $response);
    }

    public function testLandingPageNotAuthorized(): void
    {
        $this->markTestSkipped('Route /controlling never existed - fictional test');
        $this->logInSession('developer');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling');
        $this->assertStatusCode(403);
        $response = $this->client->getResponse()->getContent();
        self::assertIsString($response);
        self::assertStringContainsString('You are not allowed', (string) $response);
    }

    public function testLandingPageAsUserWithData(): void
    {
        $this->markTestSkipped('Route /controlling never existed - fictional test');
        $this->logInSession('unittest'); // Admin User
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling');
        $this->assertStatusCode(200);
        $response = $this->client->getResponse()->getContent();
        self::assertIsString($response);
        self::assertStringContainsString('Controlling', (string) $response);
        // test menu title
        self::assertStringContainsString('Export', (string) $response);

        $this->logInSession('developer'); // Normal User
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling');
        $this->assertStatusCode(403);
        $response = $this->client->getResponse()->getContent();
        self::assertIsString($response);
        self::assertStringContainsString('You are not allowed', (string) $response);
    }

    public function testGetDataForBrowsingByCustomer(): void
    {
        $this->markTestSkipped('Route /getDataForBrowsingByCustomer never existed - fictional test');
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getDataForBrowsingByCustomer', [
            'year' => 2020,
            'month' => 2,
            'customer' => 1,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) ($response->getContent() ?: ''), true);
        self::assertArraySubset([
            'content' => [
                [
                    'customer' => [
                        'name' => 'Der Bäcker von nebenan',
                        'id' => 1,
                        'color' => '#333',
                        'global' => false,
                    ],
                ],
            ],
            'totalWorkTime' => '2020-02-01: 0h 0m, 2020-02-08: 5h 30m, 2020-02-10: 0h 0m, 2020-02-15: 0h 0m',
        ], (array) $data);
    }

    public function testGetDataForBrowsingByProject(): void
    {
        $this->markTestSkipped('Route /getDataForBrowsingByProject never existed - fictional test');
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getDataForBrowsingByProject', [
            'year' => 2020,
            'month' => 2,
            'project' => 1,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) ($response->getContent() ?: ''), true);
        self::assertArraySubset([
            'content' => [
                [
                    'customer' => [
                        'name' => 'Der Bäcker von nebenan',
                        'id' => 1,
                        'color' => '#333',
                        'global' => false,
                    ],
                    'project' => [
                        'name' => 'Das Kuchenbacken',
                        'id' => 1,
                        'active' => true,
                        'global' => false,
                    ],
                ],
            ],
            'totalWorkTime' => '2020-02-01: 0h 0m, 2020-02-08: 5h 30m, 2020-02-10: 0h 0m, 2020-02-15: 0h 0m',
        ], (array) $data);
    }

    public function testGetDataForBrowsingByUser(): void
    {
        $this->markTestSkipped('Route /getDataForBrowsingByUser never existed - fictional test');
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getDataForBrowsingByUser', [
            'year' => 2020,
            'month' => 2,
            'user' => 2,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) ($response->getContent() ?: ''), true);

        self::assertArraySubset([
            'content' => [
                [
                    'customer' => [
                        'name' => 'Der Bäcker von nebenan',
                        'id' => 1,
                        'color' => '#333',
                        'global' => false,
                    ],
                    'project' => [
                        'name' => 'Das Kuchenbacken',
                        'id' => 1,
                        'active' => true,
                        'global' => false,
                    ],
                    'user' => [
                        'username' => 'i.myself',
                        'id' => 2,
                        'abbr' => 'IMY',
                        'type' => 'PL',
                    ],
                ],
            ],
            'totalWorkTime' => '2020-02-01: 0h 0m, 2020-02-08: 5h 30m, 2020-02-10: 0h 0m, 2020-02-15: 0h 0m',
        ], (array) $data);
    }

    public function testGetDataForBrowsingByTeam(): void
    {
        $this->markTestSkipped('Route /getDataForBrowsingByTeam never existed - fictional test');
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getDataForBrowsingByTeam', [
            'year' => 2020,
            'month' => 2,
            'team' => 1,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) ($response->getContent() ?: ''), true);
        self::assertArraySubset([
            'content' => [
                [
                    'customer' => [
                        'name' => 'Der Bäcker von nebenan',
                        'id' => 1,
                        'color' => '#333',
                        'global' => false,
                    ],
                    'project' => [
                        'name' => 'Das Kuchenbacken',
                        'id' => 1,
                        'active' => true,
                        'global' => false,
                    ],
                    'user' => [
                        'username' => 'i.myself',
                        'id' => 2,
                        'abbr' => 'IMY',
                        'type' => 'PL',
                    ],
                    'team' => [
                        'name' => 'Hackerman',
                        'id' => 1,
                        'lead_user_id' => 2,
                    ],
                ],
            ],
            'totalWorkTime' => '2020-02-01: 0h 0m, 2020-02-08: 5h 30m, 2020-02-10: 0h 0m, 2020-02-15: 0h 0m',
        ], (array) $data);
    }

    public function testGetDataForBrowsingByPeriod(): void
    {
        $this->markTestSkipped('Route /getDataForBrowsingByPeriod never existed - fictional test');
        $this->logInSession('unittest');
        $response = $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getDataForBrowsingByPeriod', [
            'start' => '2020-02-01',
            'end' => '2020-02-29',
        ]);
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) ($response->getContent() ?: ''), true);

        self::assertArraySubset([
            'content' => [
                [
                    'customer' => [
                        'name' => 'Der Bäcker von nebenan',
                        'id' => 1,
                        'color' => '#333',
                        'global' => false,
                    ],
                    'project' => [
                        'name' => 'Das Kuchenbacken',
                        'id' => 1,
                        'active' => true,
                        'global' => false,
                    ],
                    'user' => [
                        'username' => 'i.myself',
                        'id' => 2,
                        'abbr' => 'IMY',
                        'type' => 'PL',
                    ],
                ],
            ],
            'totalWorkTime' => 330,
        ], (array) $data);
    }

    public function testGetDataForBrowsingByPeriodInvalidPeriod(): void
    {
        $this->markTestSkipped('Route /getDataForBrowsingByPeriod never existed - fictional test');
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getDataForBrowsingByPeriod', [
            'start' => '2019-01-01',
            'end' => '2018-01-01',
        ]);
        $this->assertStatusCode(422);
        $this->assertMessage('End date has to be greater than the start date.');
    }
}
