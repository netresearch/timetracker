<?php
/**
 * Netresearch Timetracker
 *
 * PHP version 5
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Controller
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */

namespace Netresearch\TimeTrackerBundle\Helper;

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
    public function readCell($column, $row, $worksheetName = '') {
        if (Coordinate::columnIndexFromString($column) > 1024) {
            return false;
        }
        return true;
    }
}
