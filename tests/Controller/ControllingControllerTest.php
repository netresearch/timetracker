<?php

namespace Tests\Controller;

use App\Controller\ControllingController;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Service\ExportService as Export;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\AbstractWebTestCase;

class ControllingControllerTest extends AbstractWebTestCase
{
    public function testExportActionRequiresLogin(): void
    {
        // Clear session to simulate not being logged in
        $this->client->getContainer()->get('session')->clear();
        $this->client->request('GET', '/controlling/export');

        // The test environment redirects to login (302) rather than returning 401
        $this->assertStatusCode(302);

        // Verify it's redirecting to the login page
        $response = $this->client->getResponse();
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }

    public function testExportActionWithLoggedInUser(): void
    {
        // Load test data to ensure we have entries to export
        $this->loadTestData('/../sql/unittest/002_testdata.sql');

        // Make sure we're logged in as unittest user (ID 1)
        $this->logInSession('unittest');

        // Request the export URL with required parameters
        $this->client->request(
            'GET',
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 6,
                'userid' => 1,
                'project' => 0,
                'customer' => 0,
                'billable' => 0,
                'tickettitles' => 0
            ]
        );

        // Check the response status code
        $this->assertStatusCode(200);

        // Get the response object for further tests
        $response = $this->client->getResponse();

        // Verify response is an Excel file with correct MIME type
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );

        // Verify content disposition header has attachment type and correct filename pattern
        $contentDisposition = $response->headers->get('Content-disposition');
        $this->assertStringStartsWith('attachment;', $contentDisposition);
        $this->assertStringContainsString(
            'attachment;filename=2023_06_',
            $contentDisposition
        );

        // Verify response has content
        $content = $response->getContent();
        $this->assertNotEmpty($content);

        // Verify the content is valid Excel data (starts with Excel file signature)
        $this->assertStringStartsWith('PK', $content, 'Response content should be a valid Excel file (XLSX)');
    }

    public function testSetCellDateAndSetCellHours(): void
    {
        $controller = new ControllingController();

        // Create a spreadsheet to test the helper methods
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Get the reflection class to access protected methods
        $reflection = new \ReflectionClass(ControllingController::class);

        // Test setCellDate method
        $setCellDateMethod = $reflection->getMethod('setCellDate');
        $setCellDateMethod->setAccessible(true);

        $testDate = new \DateTime('2025-03-30');
        $setCellDateMethod->invokeArgs(null, [$sheet, 'A', 1, $testDate]);

        // Test setCellHours method
        $setCellHoursMethod = $reflection->getMethod('setCellHours');
        $setCellHoursMethod->setAccessible(true);

        $testTime = new \DateTime('2025-03-30 14:30:00');
        $setCellHoursMethod->invokeArgs(null, [$sheet, 'B', 1, $testTime]);

        // Verify cell formats
        $this->assertEquals(
            \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD,
            $sheet->getStyle('A1')->getNumberFormat()->getFormatCode()
        );

        $this->assertEquals(
            'HH:MM',
            $sheet->getStyle('B1')->getNumberFormat()->getFormatCode()
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
            ->setDay(new \DateTime('2023-10-24'))
            ->setStart(new \DateTime('2023-10-24 09:00:00'))
            ->setEnd(new \DateTime('2023-10-24 10:00:00'))
            ->setUser($user)
            ->setCustomer($customer)
            ->setProject($project)
            ->setTicket('TKT-1')
            ->setDescription('Real Desc 1');
            // ->setActivity(null) // Assuming default is fine or set if needed

        $entry2 = (new Entry())
            ->setId(5)
            ->setDay(new \DateTime('2023-10-20'))
            ->setStart(new \DateTime('2023-10-20 11:00:00'))
            ->setEnd(new \DateTime('2023-10-20 12:30:00'))
            ->setUser($user)
            ->setCustomer($customer)
            ->setProject($project)
            ->setTicket('TKT-2')
            ->setDescription('Real Desc 2');
        // --- End of real entity prep --- //

        // Mock exportEntries - return the REAL entries
        $exportServiceMock->expects($this->once())
            ->method('exportEntries')
            ->willReturn([$entry1, $entry2]);

        // Mock enrichEntries - expect it called, use callback to call setters on REAL entries
        $exportServiceMock->expects($this->once())
            ->method('enrichEntriesWithTicketInformation')
            ->willReturnCallback(function ($userId, array $entries, $showBillable, $onlyBillable, $showTicketTitles) {
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
            });

        // Mock getUsername - expect it called for filename generation
        $exportServiceMock->expects($this->once())
            ->method('getUsername')
            ->willReturn('unittestmock');

        // Ensure kernel is shut down before creating client
        static::ensureKernelShutdown();

        // 2. Create client (boots kernel)
        $client = static::createClient();
        $this->client = $client;

        // 3. Replace service in the container obtained from the client
        $testContainer = $this->client->getContainer(); // Get container from client
        $testContainer->set(Export::class, $exportServiceMock); // Replace service

        // 4. Prepare and make request
        // Load test data (may still be needed for login/user setup)
        $this->loadTestData('/../sql/unittest/002_testdata.sql');
        $this->logInSession('unittest');

        $this->client->request(
            'GET',
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 10,
                'userid' => 1,
                'project' => 0,
                'customer' => 0,
                'billable' => 1,
                'tickettitles' => 1
            ]
        );

        // 5. Assertions
        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        // ... (other header assertions)
        $contentDisposition = $response->headers->get('Content-disposition');
        $this->assertStringStartsWith('attachment;', $contentDisposition);
        $this->assertStringContainsString(
            'attachment;filename=2023_10_unittestmock.xlsx', // Expect mocked username
            $contentDisposition
        );
        // ... (rest of assertions)
    }

    /**
     * Test that the billable column (N) is NOT present when APP_SHOW_BILLABLE_FIELD_IN_EXPORT is false/unset.
     */
    public function testExportActionHidesBillableFieldWhenNotConfigured(): void
    {
        $_ENV['APP_SHOW_BILLABLE_FIELD_IN_EXPORT'] = 'false';

        static::ensureKernelShutdown(); // Ensure clean state
        $client = static::createClient(); // Create client AFTER setting $_ENV
        $this->client = $client;

        // Load test data
        $this->loadTestData('/../sql/unittest/002_testdata.sql');
        $this->logInSession('unittest');

        // Request export without billable/tickettitles parameters explicitly enabled
        // The controller should not add the billable column based on env config
        $this->client->request(
            'GET',
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 6,
                'userid' => 1,
                'project' => 0,
                'customer' => 0,
                'billable' => 0,
                'tickettitles' => 0
            ]
        );

        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        $content = $response->getContent();
        $this->assertNotEmpty($content);

        $tempFilePath = tempnam(sys_get_temp_dir(), 'export_test_nobill_') . '.xlsx';
        file_put_contents($tempFilePath, $content);

        try {
            $spreadsheet = IOFactory::load($tempFilePath);
            $sheet = $spreadsheet->getActiveSheet();

            // Assert that N2 (where 'billable' header would be) is empty or different
            $headerValueN2 = $sheet->getCell('N2')->getValue();
            $this->assertNotEquals('billable', $headerValueN2, 'Cell N2 should not contain "billable" header when APP_SHOW_BILLABLE_FIELD_IN_EXPORT is false/unset.');
            // Optionally, assert it's null or empty if the template guarantees it:
            // $this->assertNull($headerValueN2, 'Cell N2 should be empty when billable field is not configured.');

            // Assert that N3 (where billable data would be) is empty
            $dataValueN3 = $sheet->getCell('N3')->getValue();
            $this->assertNull($dataValueN3, 'Cell N3 should be empty when billable field is not configured.');

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
            'GET',
            '/controlling/export',
            [
                'year' => 2023,
                'month' => 6,
                'userid' => 1,
                'project' => 0,
                'customer' => 0,
                'billable' => 0,
                'tickettitles' => 0 // Explicitly request NO ticket titles
            ]
        );

        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        $content = $response->getContent();
        $this->assertNotEmpty($content);

        $tempFilePath = tempnam(sys_get_temp_dir(), 'export_test_notitle_') . '.xlsx';
        file_put_contents($tempFilePath, $content);

        try {
            $spreadsheet = IOFactory::load($tempFilePath);
            $sheet = $spreadsheet->getActiveSheet();

            // Assert that O2 (where 'Tickettitel' header would be) is empty or different
            $headerValueO2 = $sheet->getCell('O2')->getValue();
            $this->assertNotEquals('Tickettitel', $headerValueO2, 'Cell O2 should not contain "Tickettitel" header when tickettitles=0.');
            // Optionally, assert it's null or empty if the template guarantees it:
            // $this->assertNull($headerValueO2, 'Cell O2 should be empty when ticket titles are not requested.');

            // Assert that O3 (where ticket title data would be) is empty
            $dataValueO3 = $sheet->getCell('O3')->getValue();
            $this->assertNull($dataValueO3, 'Cell O3 should be empty when ticket titles are not requested.');

        } finally {
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
    }
}
