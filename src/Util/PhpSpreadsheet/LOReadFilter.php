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

/**
 * Workaround for phpoffice/phpspreadsheet bug #667
 * > The data could not be loaded completely because the maximum
 * > number of columns per sheet was exceeded.
 *
 * https://github.com/PHPOffice/PhpSpreadsheet/issues/667
 *
 * @author Christian Weiske <weiske@mogic.com>
 */
class LOReadFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter
{
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return Coordinate::columnIndexFromString($columnAddress) <= 1024;
    }
}
