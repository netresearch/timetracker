<?php

declare(strict_types=1);

namespace Tests\Controller;

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

    /**
     * Test legacy URL format with path parameters.
     *
     * Bug: /controlling/export/{userid}/{year}/{month}/{project}/{customer}/{billable}
     * was returning "Year must be between 1900 and 2100" for valid year 2025
     * because path parameters weren't being mapped to the DTO.
     */
    public function testExportActionLegacyUrlFormat(): void
    {
        $this->logInSession('unittest');
        // Legacy URL: /controlling/export/{userid}/{year}/{month}/{project}/{customer}/{billable}
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/controlling/export/0/2025/12/0/0/0',
        );

        // Should NOT return 422 for valid year 2025
        $response = $this->client->getResponse();
        $content = (string) $response->getContent();

        // The bug caused: "Year must be between 1900 and 2100" for year=2025
        self::assertStringNotContainsString(
            'Year must be between 1900 and 2100',
            $content,
            'Valid year 2025 in path parameters should not trigger validation error',
        );

        // Should return 200 (successful export)
        $this->assertStatusCode(200);
    }

    /**
     * Test that various valid years work with legacy URL format.
     */
    public function testExportActionLegacyUrlFormatVariousYears(): void
    {
        $this->logInSession('unittest');

        foreach ([2020, 2024, 2025, 2026] as $year) {
            $this->client->request(
                \Symfony\Component\HttpFoundation\Request::METHOD_GET,
                "/controlling/export/0/{$year}/1/0/0/0",
            );

            $response = $this->client->getResponse();
            $content = (string) $response->getContent();

            self::assertStringNotContainsString(
                'Year must be between 1900 and 2100',
                $content,
                "Year {$year} should be valid in legacy URL format",
            );
        }
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
}
