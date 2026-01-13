<?php

declare(strict_types=1);

/**
 * Netresearch Timetracker.
 *
 * PHP version 8
 *
 * @category   Netresearch
 *
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */

namespace App\Util\PhpSpreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PERMANENT WORKAROUND for PhpSpreadsheet bug #667 - LibreOffice column limit.
 *
 * This filter prevents the LibreOffice error:
 * "The data could not be loaded completely because the maximum
 * number of columns per sheet was exceeded."
 *
 * ## Why This Is Permanent
 *
 * This workaround will ALWAYS be needed because:
 * - LibreOffice has a hard limit of 1024 columns (vs Excel's 16384)
 * - PhpSpreadsheet maintainers explicitly rejected fixing this (PR #1289)
 * - They prioritize Excel compatibility over LibreOffice limitations
 * - The issue remains open and unresolved even in latest versions
 *
 * ## Technical Details
 *
 * LibreOffice Calc files often contain metadata for column 1025+, which causes
 * PhpSpreadsheet to process all 16384 potential columns. This filter limits
 * reading to the first 1024 columns, preventing the error dialog in LibreOffice.
 *
 * Without this filter, exported files would show errors when opened in LibreOffice,
 * breaking the workflow for users who use LibreOffice instead of Excel.
 *
 * @see https://github.com/PHPOffice/PhpSpreadsheet/issues/667
 * @see https://github.com/PHPOffice/PhpSpreadsheet/pull/1289 (rejected PR)
 *
 * @author Christian Weiske <weiske@mogic.com>
 */
class LOReadFilter implements IReadFilter
{
    /**
     * Maximum number of columns allowed in LibreOffice Calc.
     */
    private const int MAX_LIBREOFFICE_COLUMNS = 1024;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Determines whether a cell should be read based on LibreOffice column limitations.
     *
     * @param string $columnAddress Column address (e.g., 'A', 'AA', 'AMJ')
     * @param int    $row           Row number
     * @param string $worksheetName Optional worksheet name
     *
     * @return bool True if the cell should be read (column <= 1024), false otherwise
     */
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        $columnIndex = Coordinate::columnIndexFromString($columnAddress);
        $shouldRead = $columnIndex <= self::MAX_LIBREOFFICE_COLUMNS;

        if (!$shouldRead) {
            $this->logger->debug('LOReadFilter blocked column beyond LibreOffice limit', [
                'column' => $columnAddress,
                'column_index' => $columnIndex,
                'row' => $row,
                'worksheet' => $worksheetName,
                'limit' => self::MAX_LIBREOFFICE_COLUMNS,
            ]);
        }

        return $shouldRead;
    }
}
