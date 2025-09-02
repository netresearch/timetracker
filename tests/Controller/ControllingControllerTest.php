<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\ControllingController;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Service\ExportService as Export;
use DateTime;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ControllingControllerTest extends AbstractWebTestCase
{
    public function testExportActionRequiresLogin(): void
    {
        // Clear session to simulate not being logged in and persist the change
        $session = $this->client->getContainer()->get('session');
        $session->clear();
        if (method_exists($session, 'save')) {
            $session->save();
        }

        // Clear cookies so no previous session id is reused
        $this->client->getCookieJar()->clear();

        // Also clear the security token to ensure full logout in test env
        if ($this->client->getContainer()->has('security.token_storage')) {
            $this->client->getContainer()->get('security.token_storage')->setToken(null);
        }
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/controlling/export');

        // The test environment redirects to login (302) rather than returning 401
        $this->assertStatusCode(302);

        // Verify it's redirecting to the login page
        $response = $this->client->getResponse();
        self::assertStringContainsString('/login', $response->headers->get('Location'));
    }

    public function testExportActionWithLoggedInUser(): void
    {
        // Load test data to ensure we have entries to export
        $this->loadTestData('/../sql/unittest/002_testdata.sql');

        // Make sure we're logged in as unittest user (ID 1)
        $this->logInSession('unittest');

        // Request the export URL with required parameters
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 6,
                'userid' => 1,
                'project' => 0,
                'customer' => 0,
                'billable' => 0,
                'tickettitles' => 0,
            ],
        );

        // Check the response status code
        $this->assertStatusCode(200);

        // Get the response object for further tests
        $response = $this->client->getResponse();

        // Verify response is an Excel file with correct MIME type
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );

        // Verify content disposition header has attachment type and correct filename pattern
        $contentDisposition = $response->headers->get('Content-disposition');
        self::assertStringStartsWith('attachment;', $contentDisposition);
        self::assertStringContainsString(
            'attachment;filename=2023_06_',
            $contentDisposition,
        );

        // Verify response has content
        $content = $response->getContent();
        self::assertNotEmpty($content);

        // Verify the content is valid Excel data (starts with Excel file signature)
        self::assertStringStartsWith('PK', $content, 'Response content should be a valid Excel file (XLSX)');
    }

    public function testSetCellDateAndSetCellHours(): void
    {
        new ControllingController();

        // Create a spreadsheet to test the helper methods
        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();

        // Get the reflection class to access protected methods
        $reflectionClass = new ReflectionClass(ControllingController::class);

        // Test setCellDate method
        $reflectionMethod = $reflectionClass->getMethod('setCellDate');

        $testDate = new DateTime('2025-03-30');
        $reflectionMethod->invokeArgs(null, [$worksheet, 'A', 1, $testDate]);

        // Test setCellHours method
        $setCellHoursMethod = $reflectionClass->getMethod('setCellHours');

        $testTime = new DateTime('2025-03-30 14:30:00');
        $setCellHoursMethod->invokeArgs(null, [$worksheet, 'B', 1, $testTime]);

        // Verify cell formats
        self::assertSame(
            \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD,
            $worksheet->getStyle('A1')->getNumberFormat()->getFormatCode(),
        );

        self::assertSame(
            'HH:MM',
            $worksheet->getStyle('B1')->getNumberFormat()->getFormatCode(),
        );
    }

    public function testExportActionWithBillableAndTicketTitles(): void
    {
        // 1. Create mock for the Export service
        /** @var Export|MockObject $exportServiceMock */
        $exportServiceMock = $this->createMock(Export::class);

        // --- Prepare REAL entity objects for the mock to return --- //
        $user = (new User())->setAbbr('TST'); // Minimal user
        $customer = (new Customer())->setName('Test Customer'); // Real customer with name
        $project = (new Project())->setName('Test Project')->setCustomer($customer); // Real project with name and linked customer

        // Real Entry objects with necessary data
        $entry1 = (new Entry())
            ->setId(4)
            ->setDay(new DateTime('2023-10-24'))
            ->setStart(new DateTime('2023-10-24 09:00:00'))
            ->setEnd(new DateTime('2023-10-24 10:00:00'))
            ->setUser($user)
            ->setCustomer($customer)
            ->setProject($project)
            ->setTicket('TKT-1')
            ->setDescription('Real Desc 1')
        ;
        // ->setActivity(null) // Assuming default is fine or set if needed

        $entry2 = (new Entry())
            ->setId(5)
            ->setDay(new DateTime('2023-10-20'))
            ->setStart(new DateTime('2023-10-20 11:00:00'))
            ->setEnd(new DateTime('2023-10-20 12:30:00'))
            ->setUser($user)
            ->setCustomer($customer)
            ->setProject($project)
            ->setTicket('TKT-2')
            ->setDescription('Real Desc 2')
        ;
        // --- End of real entity prep --- //

        // Mock exportEntries - return the REAL entries
        $exportServiceMock->expects(self::once())
            ->method('exportEntries')
            ->willReturn([$entry1, $entry2])
        ;

        // Mock enrichEntries - expect it called, use callback to call setters on REAL entries
        $exportServiceMock->expects(self::once())
            ->method('enrichEntriesWithTicketInformation')
            ->willReturnCallback(static function ($userId, array $entries, $showBillable, $onlyBillable, $showTicketTitles): array {
                foreach ($entries as $entry) {
                    // Use setters ON THE REAL Entry objects
                    if ($showBillable && method_exists($entry, 'setBillable')) {
                        $entry->setBillable(true);
                    }

                    if ($showTicketTitles && method_exists($entry, 'setTicketTitle')) {
                        $entry->setTicketTitle('Mocked Title for ' . $entry->getTicket());
                    }
                }

                return $entries;
            })
        ;

        // Mock getUsername - expect it called for filename generation
        $exportServiceMock->expects(self::once())
            ->method('getUsername')
            ->willReturn('unittestmock')
        ;

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
        // Load test data (may still be needed for login/user setup)
        $this->loadTestData('/../sql/unittest/002_testdata.sql');
        $this->logInSession('unittest');

        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 10,
                'userid' => 1,
                'project' => 0,
                'customer' => 0,
                'billable' => 1,
                'tickettitles' => 1,
            ],
        );

        // 5. Assertions
        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        // ... (other header assertions)
        $contentDisposition = $response->headers->get('Content-disposition');
        self::assertStringStartsWith('attachment;', $contentDisposition);
        self::assertStringContainsString(
            'attachment;filename=2023_10_unittestmock.xlsx', // Expect mocked username
            $contentDisposition,
        );
        // ... (rest of assertions)
    }

    /**
     * Test that the billable column (N) is NOT present when APP_SHOW_BILLABLE_FIELD_IN_EXPORT is false/unset.
     */
    public function testExportActionHidesBillableFieldWhenNotConfigured(): void
    {
        $_ENV['APP_SHOW_BILLABLE_FIELD_IN_EXPORT'] = 'false';

        self::ensureKernelShutdown(); // Ensure clean state
        $client = self::createClient(); // Create client AFTER setting $_ENV
        $this->client = $client;

        $this->serviceContainer = $this->client->getContainer();

        // Load test data
        $this->loadTestData('/../sql/unittest/002_testdata.sql');
        $this->logInSession('unittest');

        // Request export without billable/tickettitles parameters explicitly enabled
        // The controller should not add the billable column based on env config
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 6,
                'userid' => 1,
                'project' => 0,
                'customer' => 0,
                'billable' => 0,
                'tickettitles' => 0,
            ],
        );

        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        $content = $response->getContent();
        self::assertNotEmpty($content);

        $tempFilePath = tempnam(sys_get_temp_dir(), 'export_test_nobill_') . '.xlsx';
        file_put_contents($tempFilePath, $content);

        try {
            $spreadsheet = IOFactory::load($tempFilePath);
            $sheet = $spreadsheet->getActiveSheet();

            // Assert that N2 (where 'billable' header would be) is empty or different
            $headerValueN2 = $sheet->getCell('N2')->getValue();
            self::assertNotSame('billable', $headerValueN2, 'Cell N2 should not contain "billable" header when APP_SHOW_BILLABLE_FIELD_IN_EXPORT is false/unset.');
            // Optionally, assert it's null or empty if the template guarantees it:
            // $this->assertNull($headerValueN2, 'Cell N2 should be empty when billable field is not configured.');

            // Assert that N3 (where billable data would be) is empty
            $dataValueN3 = $sheet->getCell('N3')->getValue();
            self::assertNull($dataValueN3, 'Cell N3 should be empty when billable field is not configured.');
        } finally {
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
    }

    /**
     * Test that the ticket title column (O) is NOT present when tickettitles=0.
     */
    public function testExportActionHidesTicketTitleWhenNotRequested(): void
    {
        // Load test data
        $this->loadTestData('/../sql/unittest/002_testdata.sql');
        $this->logInSession('unittest');

        // Request export with tickettitles=0
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 6,
                'userid' => 1,
                'project' => 0,
                'customer' => 0,
                'billable' => 0,
                'tickettitles' => 0, // Explicitly request NO ticket titles
            ],
        );

        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        $content = $response->getContent();
        self::assertNotEmpty($content);

        $tempFilePath = tempnam(sys_get_temp_dir(), 'export_test_notitle_') . '.xlsx';
        file_put_contents($tempFilePath, $content);

        try {
            $spreadsheet = IOFactory::load($tempFilePath);
            $sheet = $spreadsheet->getActiveSheet();

            // Assert that O2 (where 'Tickettitel' header would be) is empty or different
            $headerValueO2 = $sheet->getCell('O2')->getValue();
            self::assertNotSame('Tickettitel', $headerValueO2, 'Cell O2 should not contain "Tickettitel" header when tickettitles=0.');
            // Optionally, assert it's null or empty if the template guarantees it:
            // $this->assertNull($headerValueO2, 'Cell O2 should be empty when ticket titles are not requested.');

            // Assert that O3 (where ticket title data would be) is empty
            $dataValueO3 = $sheet->getCell('O3')->getValue();
            self::assertNull($dataValueO3, 'Cell O3 should be empty when ticket titles are not requested.');
        } finally {
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
    }
}
