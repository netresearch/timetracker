<?php

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

use App\Model\Response;
use App\Service\ExportService as Export;
use App\Util\PhpSpreadsheet\LOReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;

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
    private Export $export;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setExportService(Export $export): void
    {
        $this->export = $export;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/controlling/export', name: '_controllingExport_attr', methods: ['GET'])]
    #[\Symfony\Component\Routing\Attribute\Route(path: '/controlling/export/{userid}/{year}/{month}/{project}/{customer}/{billable}', name: '_controllingExport_bc', methods: ['GET'], requirements: ['year' => '\\\\d+', 'userid' => '\\\\d+'], defaults: ['userid' => 0, 'year' => 0, 'month' => 0, 'project' => 0, 'customer' => 0, 'billable' => 0])]
    public function export(Request $request): Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        // Must be fully authenticated AND have a session token present
        // Redirect to login when session token is not present (test clears session explicitly)
        // Require presence of session security token to consider the user logged in
        if (null !== $this->container && $this->container->has('session')) {
            $session = $this->container->get('session');
        } else {
            $session = $request->getSession();
        }

        if (null === $session || !$session->has('_security_main') || empty($session->get('_security_main'))) {
            return $this->login($request);
        }

        // Map legacy path parameters to query parameters for backward compatibility
        $attributeKeysToMap = ['project', 'userid', 'year', 'month', 'customer', 'billable'];
        foreach ($attributeKeysToMap as $attributeKeyToMap) {
            if ($request->attributes->has($attributeKeyToMap) && !$request->query->has($attributeKeyToMap)) {
                $request->query->set($attributeKeyToMap, (string) $request->attributes->get($attributeKeyToMap));
            }
        }

        $projectId = (int) $request->query->get('project');
        $userId = (int) $request->query->get('userid');
        $year = (int) $request->query->get('year');
        $month = (int) $request->query->get('month');
        $customerId = (int) $request->query->get('customer');
        $onlyBillable = (bool) $request->query->get('billable');
        $showTicketTitles = (bool) $request->query->get('tickettitles');

        $service = $this->export;
        /** @var array<int, \App\Entity\Entry> $entries */
        $entries = $service->exportEntries(
            $userId,
            $year,
            $month,
            $projectId,
            $customerId,
            [
                'user.username' => true,
                'entry.day' => true,
                'entry.start' => true,
            ]
        );

        $showBillableField = $this->params->has('app_show_billable_field_in_export')
            && (bool) $this->params->get('app_show_billable_field_in_export');
        if ($showBillableField || $showTicketTitles) {
            $entries = $service->enrichEntriesWithTicketInformation(
                $this->getUserId($request),
                $entries,
                $showBillableField,
                $onlyBillable,
                $showTicketTitles
            );
        }

        $username = (string) $service->getUsername($userId);

        $filename = strtolower(
            $year.'_'
            .str_pad((string) $month, 2, '0', STR_PAD_LEFT)
            .'_'
            .str_replace(' ', '-', $username)
        );

        // $spreadsheet = new Spreadsheet();
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $reader->setReadFilter(new LOReadFilter());

        $spreadsheet = $reader->load(
            $this->kernel->getProjectDir().'/public/template.xlsx'
        );

        $sheet = $spreadsheet->getSheet(0);

        $headingStyle = [
            'font' => [
                'bold' => true,
            ],
        ];
        if ($showBillableField) {
            // add header
            $sheet->setCellValue('N2', 'billable');
            $sheet->getStyle('N2')->applyFromArray($headingStyle);
        }

        if ($showTicketTitles) {
            $sheet->setCellValue('O2', 'Tickettitel');
            $sheet->getStyle('O2')->applyFromArray($headingStyle);
        }

        // https://jira.netresearch.de/browse/TTT-561
        $lineNumber = 3;
        $stats = [];
        foreach ($entries as $entry) {
            $abbr = $entry->getUser() ? (string) $entry->getUser()->getAbbr() : '';
            if (!isset($stats[$abbr])) {
                $stats[$abbr] = [
                    'holidays' => 0,
                    'sickdays' => 0,
                ];
            }

            $activity = $entry->getActivity();

            if (!is_null($activity)) {
                if ($activity->isHoliday()) {
                    ++$stats[$abbr]['holidays'];
                }

                if ($activity->isSick()) {
                    ++$stats[$abbr]['sickdays'];
                }

                $activity = $activity->getName();
            } else {
                $activity = ' ';
            }

            if ($entry->getDay() instanceof \DateTimeInterface) {
                self::setCellDate($sheet, 'A', $lineNumber, $entry->getDay());
            }

            if ($entry->getStart() instanceof \DateTimeInterface) {
                self::setCellHours($sheet, 'B', $lineNumber, $entry->getStart());
            }
            if ($entry->getEnd() instanceof \DateTimeInterface) {
                self::setCellHours($sheet, 'C', $lineNumber, $entry->getEnd());
            }
            $sheet->setCellValue(
                'D'.$lineNumber,
                (($entry->getCustomer() && $entry->getCustomer()->getName())
                    ? (string) $entry->getCustomer()->getName()
                    : (($entry->getProject() && $entry->getProject()->getCustomer())
                        ? (string) $entry->getProject()->getCustomer()->getName()
                        : ''))
            );
            $sheet->setCellValue('E'.$lineNumber, $entry->getProject() ? (string) $entry->getProject()->getName() : '');
            $sheet->setCellValue('F'.$lineNumber, $activity);
            $sheet->setCellValue('G'.$lineNumber, $entry->getDescription());
            $sheet->setCellValue('H'.$lineNumber, $entry->getTicket());

            // $sheet->setCellValue('I', $lineNumber, $entry->getDuration());
            $sheet->setCellValue('I'.$lineNumber, '=C'.$lineNumber.'-B'.$lineNumber);

            $sheet->setCellValue('J'.$lineNumber, $abbr);
            $sheet->setCellValue('K'.$lineNumber, $entry->getExternalReporter());
            $sheet->setCellValue('L'.$lineNumber, $entry->getExternalSummary());
            $sheet->setCellValue('M'.$lineNumber, implode(', ', $entry->getExternalLabels()));
            if ($showBillableField) {
                $sheet->setCellValue('N'.$lineNumber, (int) ((bool) $entry->getBillable()));
            }

            if ($showTicketTitles) {
                $sheet->setCellValue('O'.$lineNumber, $entry->getTicketTitle());
            }

            ++$lineNumber;
        }

        // TODO: https://jira.netresearch.de/browse/TTT-559
        // sheet 2: list user working days without working time
        $spreadsheet->getSheet(1);

        // TODO: https://jira.netresearch.de/browse/TTT-560
        // sheet 3: list users monthly SOLL/IST, holidays, sickdays
        $sheet = $spreadsheet->getSheet(2);
        $lineNumber = 2;
        ksort($stats);
        foreach ($stats as $user => $userStats) {
            $sheet->setCellValue('A'.$lineNumber, $user);
            $sheet->setCellValue('B'.$lineNumber, $month);
            // $sheet->setCellValue('C' . $lineNumber, 0);

            // =SUMIF(ZE!$J$1:$J$5000,A3,ZE!$I$1:$I$5000)
            // [HH]:MM
            $sheet->setCellValue('D'.$lineNumber, '=SUMIF(ZE!$J$1:$J$5000,A'.$lineNumber.',ZE!$I$1:$I$5000)');
            $sheet->getStyle('D'.$lineNumber)
                ->getNumberFormat()
                ->setFormatCode('[HH]:MM');

            if ($userStats['holidays'] > 0) {
                $sheet->setCellValue('E'.$lineNumber, $userStats['holidays']);
            }

            if ($userStats['sickdays'] > 0) {
                $sheet->setCellValue('F'.$lineNumber, $userStats['sickdays']);
            }

            ++$lineNumber;
        }

        $file = tempnam(sys_get_temp_dir(), 'ttt-export-');
        $xlsx = new Xlsx($spreadsheet);
        $xlsx->save($file);

        $response = new Response();
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-disposition', 'attachment;filename='.$filename.'.xlsx');

        $content = file_get_contents($file) ?: '';
        $response->setContent($content);

        unlink($file);

        return $response;
    }

    /**
     * Set cell value to numeric date value and given display format.
     *
     * @param Worksheet          $worksheet Spreadsheet
     * @param string             $column    Spreadsheet column
     * @param int                $row       Spreadsheet row
     * @param \DateTimeInterface $date      Date should be inserted
     * @param string             $format    Display date format
     *
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     *
     * @return void
     */
    protected static function setCellDate(Worksheet $worksheet, string $column, int $row, \DateTimeInterface $date, string $format = \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD)
    {
        // Set date value
        $worksheet->setCellValue(
            $column.$row,
            \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date)
        );

        // Set the number format mask so that the excel timestamp will be displayed as a human-readable date/time
        $worksheet->getStyle($column.$row)
            ->getNumberFormat()
            ->setFormatCode($format);
    }

    /**
     * Set cell value to a numeric time value and display format to HH::MM.
     *
     * @param Worksheet          $worksheet Spreadsheet
     * @param string             $column    Spreadsheet column
     * @param int                $row       Spreadsheet row
     * @param \DateTimeInterface $date      Date with time which time value should be inserted
     *
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     *
     * @return void
     */
    protected static function setCellHours(Worksheet $worksheet, string $column, int $row, \DateTimeInterface $date)
    {
        $dateValue = (float) \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date);
        $hourValue = $dateValue - floor($dateValue);

        // Set date value
        $worksheet->setCellValue($column.$row, $hourValue);

        // Set the number format mask so that the excel timestamp will be displayed as a human-readable date/time
        $worksheet->getStyle($column.$row)
            ->getNumberFormat()
            ->setFormatCode('HH:MM');
    }
}
