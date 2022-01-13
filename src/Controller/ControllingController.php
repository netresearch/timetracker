<?php declare(strict_types=1);
/**
 * Netresearch Timetracker.
 *
 * PHP version 5
 *
 * @category   Netresearch
 *
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */

namespace App\Controller;

use PhpOffice\PhpSpreadsheet\Exception;
use App\Entity\Entry;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use App\Helper\LOReadFilter;
use App\Kernel;
use App\Model\Response;
use App\Services\Export;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class ControllingController.
 *
 * @category   Netresearch
 *
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */
class ControllingController extends BaseController
{
    /**
     * Exports a users timetable from one specific year and month.
     *
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     *
     * @return Response
     */
    #[Route(path: '//controlling/export/{userid}/{year}/{month}/{project}/{customer}/{billable}')]
    public function exportAction(Export $export, Kernel $kernel)
    {
        $projectId    = (int) $this->request->get('project');
        $userId       = (int) $this->request->get('userid');
        $year         = (int) $this->request->get('year');
        $month        = (int) $this->request->get('month');
        $customerId   = (int) $this->request->get('customer');
        $onlyBillable = (bool) $this->request->get('billable');

        /** @var Entry[] $entries */
        $entries = $export->exportEntries(
            $userId,
            $year,
            $month,
            $projectId,
            $customerId,
            [
                'user.username' => true,
                'entry.day'     => true,
                'entry.start'   => true,
            ]
        );

        $showBillableField = $this->params->has('app.show_billable_field_in_export')
            && $this->params->get('app.show_billable_field_in_export');
        if ($showBillableField) {
            $entries = $export->enrichEntriesWithBillableInformation(
                $this->getUserId(),
                $entries,
                $onlyBillable
            );
        }

        $username = $export->getUsername($userId);

        $filename = strtolower(
            $year.'_'
            .str_pad((string) $month, 2, '0', \STR_PAD_LEFT)
            .'_'
            .str_replace(' ', '-', $username)
        );

        //$spreadsheet = new Spreadsheet();
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadFilter(new LOReadFilter());
        $spreadsheet = $reader->load(
            $kernel->getProjectDir().'/assets/template.xlsx'
        );

        $sheet = $spreadsheet->getSheet(0);
        if ($showBillableField) {
            //add header
            $sheet->setCellValue('N2', 'billable');
        }

        // https://jira.netresearch.de/browse/TTT-561
        $lineNumber = 3;
        $stats      = [];
        foreach ($entries as $entry) {
            if (!isset($stats[$entry->getUser()->getAbbr()])) {
                $stats[$entry->getUser()->getAbbr()] = [
                    'holidays' => 0,
                    'sickdays' => 0,
                ];
            }

            $activity = $entry->getActivity();

            if (null !== $activity) {
                if ($activity->isHoliday()) {
                    ++$stats[$entry->getUser()->getAbbr()]['holidays'];
                }
                if ($activity->isSick()) {
                    ++$stats[$entry->getUser()->getAbbr()]['sickdays'];
                }
                $activity = $activity->getName();
            } else {
                $activity = ' ';
            }

            self::setCellDate($sheet, 'A', $lineNumber, $entry->getDay());

            self::setCellHours($sheet, 'B', $lineNumber, $entry->getStart());
            self::setCellHours($sheet, 'C', $lineNumber, $entry->getEnd());
            $sheet->setCellValue(
                'D'.$lineNumber,
                $entry->getCustomer()->getName() ?: $entry->getProject()->getCustomer()->getName()
            );
            $sheet->setCellValue('E'.$lineNumber, $entry->getProject()->getName());
            $sheet->setCellValue('F'.$lineNumber, $activity);
            $sheet->setCellValue('G'.$lineNumber, $entry->getDescription());
            $sheet->setCellValue('H'.$lineNumber, $entry->getTicket());

            //$sheet->setCellValue('I', $lineNumber, $entry->getDuration());
            $sheet->setCellValue('I'.$lineNumber, '=C'.$lineNumber.'-B'.$lineNumber);

            $sheet->setCellValue('J'.$lineNumber, $entry->getUser()->getAbbr());
            $sheet->setCellValue('K'.$lineNumber, $entry->getExternalReporter());
            $sheet->setCellValue('L'.$lineNumber, $entry->getExternalSummary());
            $sheet->setCellValue('M'.$lineNumber, implode(', ', $entry->getExternalLabels()));
            if ($showBillableField) {
                $sheet->setCellValue('N'.$lineNumber, (int) $entry->billable);
            }

            ++$lineNumber;
        }

        // TODO: https://jira.netresearch.de/browse/TTT-559
        // sheet 2: list user working days without working time
        $sheet      = $spreadsheet->getSheet(1);
        $lineNumber = 2;

        // TODO: https://jira.netresearch.de/browse/TTT-560
        // sheet 3: list users monthly SOLL/IST, holidays, sickdays
        $sheet      = $spreadsheet->getSheet(2);
        $lineNumber = 2;
        ksort($stats);
        foreach ($stats as $user => $userStats) {
            $sheet->setCellValue('A'.$lineNumber, $user);
            $sheet->setCellValue('B'.$lineNumber, $month);
            //$sheet->setCellValue('C' . $lineNumber, 0);

            // =SUMIF(ZE!$J$1:$J$5000,A3,ZE!$I$1:$I$5000)
            // [HH]:MM
            $sheet->setCellValue('D'.$lineNumber, '=SUMIF(ZE!$J$1:$J$5000,A'.$lineNumber.',ZE!$I$1:$I$5000)');
            $sheet->getStyle('D'.$lineNumber)
                ->getNumberFormat()
                ->setFormatCode('[HH]:MM')
            ;

            if ($userStats['holidays'] > 0) {
                $sheet->setCellValue('E'.$lineNumber, $userStats['holidays']);
            }

            if ($userStats['sickdays'] > 0) {
                $sheet->setCellValue('F'.$lineNumber, $userStats['sickdays']);
            }

            ++$lineNumber;
        }

        $file   = tempnam(sys_get_temp_dir(), 'ttt-export-');
        $writer = new Xlsx($spreadsheet);
        $writer->save($file);

        $response = new Response();
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-disposition', 'attachment;filename='.$filename.'.xlsx');
        $response->setContent(file_get_contents($file));

        unlink($file);

        return $response;
    }

    /**
     * Set cell value to numeric date value and given display format.
     *
     * @throws Exception
     */
    protected static function setCellDate(Worksheet $sheet, string $column, int $row, string $date, string $format = NumberFormat::FORMAT_DATE_YYYYMMDD2): void
    {
        // Set date value
        $sheet->setCellValue(
            $column.$row,
            Date::PHPToExcel($date)
        );

        // Set the number format mask so that the excel timestamp will be displayed as a human-readable date/time
        $sheet->getStyle($column.$row)
            ->getNumberFormat()
            ->setFormatCode($format)
        ;
    }

    /**
     * Set cell value to a numeric time value and display format to HH::MM.
     *
     * @throws Exception
     */
    protected static function setCellHours(Worksheet $sheet, string $column, int $row, string $date): void
    {
        $dateValue = Date::PHPToExcel($date);
        $hourValue = $dateValue - floor($dateValue);

        // Set date value
        $sheet->setCellValue($column.$row, $hourValue);

        // Set the number format mask so that the excel timestamp will be displayed as a human-readable date/time
        $sheet->getStyle($column.$row)
            ->getNumberFormat()
            ->setFormatCode('HH:MM')
        ;
    }
}
