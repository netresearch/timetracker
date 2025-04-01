<?php

namespace Tests\Controller;

use App\Controller\ControllingController;
use App\Entity\Entry;
use App\Entity\User;
use App\Services\Export;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        $this->markTestSkipped('TODO');
    }
}
