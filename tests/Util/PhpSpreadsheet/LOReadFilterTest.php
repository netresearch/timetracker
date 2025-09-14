<?php

declare(strict_types=1);

namespace Tests\Util\PhpSpreadsheet;

use App\Util\PhpSpreadsheet\LOReadFilter;
use PHPUnit\Framework\TestCase;

/**
 * Test coverage for LOReadFilter - a permanent workaround for PhpSpreadsheet bug #667.
 *
 * This filter prevents LibreOffice "maximum columns exceeded" errors by limiting
 * spreadsheet reading to 1024 columns. The bug remains unresolved in PhpSpreadsheet
 * even in the latest versions (PR #1289 was rejected by maintainers).
 *
 * @covers \App\Util\PhpSpreadsheet\LOReadFilter
 */
final class LOReadFilterTest extends TestCase
{
    private LOReadFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new LOReadFilter();
    }

    /**
     * Test that the filter correctly allows columns within the LibreOffice limit.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validColumnProvider')]
    public function testAcceptsColumnsWithinLimit(string $column, int $row): void
    {
        $result = $this->filter->readCell($column, $row);

        $this->assertTrue(
            $result,
            sprintf('Column %s (row %d) should be accepted as it is within the 1024 column limit', $column, $row)
        );
    }

    /**
     * Test that the filter correctly rejects columns beyond the LibreOffice limit.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidColumnProvider')]
    public function testRejectsColumnsBeyondLimit(string $column, int $row): void
    {
        $result = $this->filter->readCell($column, $row);

        $this->assertFalse(
            $result,
            sprintf('Column %s (row %d) should be rejected as it exceeds the 1024 column limit', $column, $row)
        );
    }

    /**
     * Test the exact boundary at column 1024 (AMJ).
     */
    public function testExactBoundaryColumn1024(): void
    {
        // Column AMJ is exactly column 1024
        $this->assertTrue(
            $this->filter->readCell('AMJ', 1),
            'Column AMJ (1024) should be the last accepted column'
        );

        // Column AMK is column 1025 and should be rejected
        $this->assertFalse(
            $this->filter->readCell('AMK', 1),
            'Column AMK (1025) should be the first rejected column'
        );
    }

    /**
     * Test that row numbers don't affect the column filtering logic.
     */
    public function testRowIndependence(): void
    {
        $testColumn = 'Z'; // Column 26, well within limit

        // Test various row numbers
        $rows = [1, 100, 1000, 10000, 1048576]; // Including Excel's max row

        foreach ($rows as $row) {
            $this->assertTrue(
                $this->filter->readCell($testColumn, $row),
                sprintf('Column %s should be accepted regardless of row %d', $testColumn, $row)
            );
        }
    }

    /**
     * Test worksheet name parameter (should be ignored but not cause errors).
     */
    public function testWorksheetNameParameter(): void
    {
        // The worksheet name is optional and should not affect the result
        $this->assertTrue(
            $this->filter->readCell('A', 1, 'Sheet1'),
            'Worksheet name should not affect column filtering'
        );

        $this->assertTrue(
            $this->filter->readCell('A', 1, ''),
            'Empty worksheet name should not affect column filtering'
        );

        $this->assertFalse(
            $this->filter->readCell('ZZZ', 1, 'AnySheet'),
            'Column beyond limit should still be rejected regardless of worksheet name'
        );
    }

    /**
     * Test common Excel column references used in templates.
     */
    public function testCommonTemplateColumns(): void
    {
        // Common columns used in time tracking exports
        $commonColumns = [
            'A' => 'Date',
            'B' => 'User',
            'C' => 'Project',
            'D' => 'Activity',
            'E' => 'Hours',
            'F' => 'Description',
            'N' => 'Billable',     // As used in ExportAction
            'O' => 'Ticket Title', // As used in ExportAction
        ];

        foreach ($commonColumns as $column => $description) {
            $this->assertTrue(
                $this->filter->readCell($column, 1),
                sprintf('Common template column %s (%s) should be accepted', $column, $description)
            );
        }
    }

    /**
     * Provides valid column addresses within the 1024 limit.
     */
    public static function validColumnProvider(): array
    {
        return [
            'First column' => ['A', 1],
            'Column B' => ['B', 2],
            'Column Z' => ['Z', 26],
            'Column AA' => ['AA', 27],
            'Column AZ' => ['AZ', 52],
            'Column BA' => ['BA', 53],
            'Column ZZ' => ['ZZ', 702],
            'Column AAA' => ['AAA', 703],
            'Column AMI' => ['AMI', 1023],  // Column 1023
            'Column AMJ' => ['AMJ', 1024],  // Column 1024 - exact limit
        ];
    }

    /**
     * Provides invalid column addresses beyond the 1024 limit.
     */
    public static function invalidColumnProvider(): array
    {
        return [
            'Column AMK' => ['AMK', 1025],   // Column 1025 - first beyond limit
            'Column AML' => ['AML', 1026],   // Column 1026
            'Column ANZ' => ['ANZ', 1078],   // Random column beyond limit
            'Column ZZZ' => ['ZZZ', 18278],  // Very high column (max for 3 chars)
            'Column XFD' => ['XFD', 16384],  // Excel's maximum column
        ];
    }

    /**
     * Test that the filter prevents the specific LibreOffice error.
     * This documents the actual error being prevented.
     */
    public function testPreventsLibreOfficeError(): void
    {
        // LibreOffice shows this error for columns > 1024:
        // "The data could not be loaded completely because the maximum
        // number of columns per sheet was exceeded."

        // Simulate reading a file with metadata in column 1025 (common in LibreOffice files)
        $libreOfficeMetadataColumn = 'AMK'; // Column 1025

        $this->assertFalse(
            $this->filter->readCell($libreOfficeMetadataColumn, 1),
            'Filter should prevent reading LibreOffice metadata columns that cause errors'
        );
    }
}