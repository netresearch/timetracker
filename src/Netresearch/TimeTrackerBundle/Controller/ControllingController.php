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

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Model\Response;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Class ControllingController
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Controller
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */
class ControllingController extends BaseController
{

    /**
     * Exports a users timetable from one specific year and month
     *
     * @return Response
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function exportAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        $userId = $this->getRequest()->get('userid');
        $year   = $this->getRequest()->get('year');
        $month  = $this->getRequest()->get('month');

        $service = $this->get('nr.timetracker.export');
        /** @var \Netresearch\TimeTrackerBundle\Entity\Entry[] $entries */
        $entries = $service->exportEntries(
            $userId, $year, $month, array(
                'user.username' => true,
                'entry.day'     => true,
                'entry.start'   => true,
            )
        );
        $username = $service->getUsername($userId);

        $filename = strtolower(
            $year . '_'
            . str_pad($month, 2, '0', STR_PAD_LEFT)
            . '_'
            . str_replace(' ', '-', $username)
        );

        //$spreadsheet = new Spreadsheet();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load('/var/www/html/web/template.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        // https://jira.netresearch.de/browse/TTT-561
        $lineNumber = 3;
        foreach ($entries as $entry) {
            self::setCellDate($sheet, 'A', $lineNumber, $entry->getDay());

            self::setCellHours($sheet, 'B', $lineNumber, $entry->getStart());
            self::setCellHours($sheet, 'C', $lineNumber, $entry->getEnd());
            $sheet->setCellValue(
                'D' . $lineNumber,
                $entry->getCustomer()->getName() ?: $entry->getProject()->getCustomer()->getName()
            );
            $sheet->setCellValue('E' . $lineNumber, $entry->getProject()->getName());
            $sheet->setCellValue('F' . $lineNumber, $entry->getActivity()->getName());
            $sheet->setCellValue('G' . $lineNumber, $entry->getDescription());
            $sheet->setCellValue('H' . $lineNumber, $entry->getTicket());

            //$sheet->setCellValue('I', $lineNumber, $entry->getDuration());
            $sheet->setCellValue('I' . $lineNumber, '=C' . $lineNumber . '-B' . $lineNumber);

            $sheet->setCellValue('J' . $lineNumber, $entry->getUser()->getAbbr());
            $sheet->setCellValue('K' . $lineNumber, $entry->getExternalReporter());
            $sheet->setCellValue('L' . $lineNumber, $entry->getExternalSummary());
            $sheet->setCellValue('M' . $lineNumber, implode(', ', $entry->getExternalLabels()));

            $lineNumber++;
        }

        // TODO: https://jira.netresearch.de/browse/TTT-559
        // sheet 2: list user working days without working time

        // TODO: https://jira.netresearch.de/browse/TTT-560
        // sheet 3: list users monthly SOLL/IST, holidays, sickdays


        $file = tempnam(sys_get_temp_dir(), 'ttt-export-');
        $writer = new Xlsx($spreadsheet);
        $writer->save($file);

        $response = new Response();
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-disposition', 'attachment;filename=' . $filename . '.xlsx');
        $response->setContent(file_get_contents($file));

        unlink($file);

        return $response;
    }

    /**
     * Set cell value to numeric date value and given display format.
     *
     * @param  Worksheet $sheet  Spreadsheet
     * @param  string    $column Spreadsheet column
     * @param  number    $row    Spreadsheet row
     * @param  string    $date   Date should be inserted
     * @param  string    $format Display date format
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @return void
     */
    protected static function setCellDate(Worksheet $sheet, $column, $row, $date, $format = \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD2)
    {
        // Set date value
        $sheet->setCellValue(
            $column . $row,
            \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date)
        );

        // Set the number format mask so that the excel timestamp will be displayed as a human-readable date/time
        $sheet->getStyle($column . $row)
            ->getNumberFormat()
            ->setFormatCode($format);
    }

    /**
     * Set cell value to a numeric time value and display format to HH::MM.
     *
     * @param  Worksheet $sheet  Spreadsheet
     * @param  string    $column Spreadsheet column
     * @param  number    $row    Spreadsheet row
     * @param  string    $date   Date with time which time value should be inserted
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @return void
     */
    protected static function setCellHours(Worksheet $sheet, $column, $row, $date)
    {
        $dateValue = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date);
        $hourValue = $dateValue - floor($dateValue);

        // Set date value
        $sheet->setCellValue($column . $row, $hourValue);

        // Set the number format mask so that the excel timestamp will be displayed as a human-readable date/time
        $sheet->getStyle($column . $row)
            ->getNumberFormat()
            ->setFormatCode('HH:MM');
    }
}
