<?php
declare(strict_types=1);

namespace App\Controller\Controlling;

use App\Controller\BaseController;
use App\Dto\ExportQueryDto;
use App\Entity\Entry;
use App\Model\Response;
use App\Service\ExportService as Export;
use App\Util\PhpSpreadsheet\LOReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;

final class ExportAction extends BaseController
{
    private Export $export;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setExportService(Export $export): void
    {
        $this->export = $export;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/controlling/export', name: '_controllingExport_attr_invokable', methods: ['GET'])]
    public function __invoke(Request $request, #[MapQueryString] ExportQueryDto $q): Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (null !== $this->container && $this->container->has('session')) {
            $session = $this->container->get('session');
        } else {
            $session = $request->getSession();
        }

        if (null === $session || !$session->has('_security_main') || empty($session->get('_security_main'))) {
            return $this->login($request);
        }

        $service = $this->export;
        /** @var array<int, Entry> $entries */
        $entries = $service->exportEntries(
            $q->userid,
            $q->year,
            $q->month,
            $q->project,
            $q->customer,
            [
                'user.username' => true,
                'entry.day' => true,
                'entry.start' => true,
            ]
        );

        $showBillableField = $this->params->has('app_show_billable_field_in_export')
            && (bool) $this->params->get('app_show_billable_field_in_export');
        if ($showBillableField || $q->tickettitles) {
            $entries = $service->enrichEntriesWithTicketInformation(
                $this->getUserId($request),
                $entries,
                $showBillableField,
                $q->billable,
                $q->tickettitles
            );
        }

        $username = (string) $service->getUsername($q->userid);

        $filename = strtolower(
            $q->year.'_'
            .str_pad((string) $q->month, 2, '0', STR_PAD_LEFT)
            .'_'
            .str_replace(' ', '-', $username)
        );

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
            $sheet->setCellValue('N2', 'billable');
            $sheet->getStyle('N2')->applyFromArray($headingStyle);
        }

        if ($q->tickettitles) {
            $sheet->setCellValue('O2', 'Tickettitel');
            $sheet->getStyle('O2')->applyFromArray($headingStyle);
        }

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
            $sheet->setCellValue('I'.$lineNumber, '=C'.$lineNumber.'-B'.$lineNumber);
            $sheet->setCellValue('J'.$lineNumber, $abbr);
            $sheet->setCellValue('K'.$lineNumber, $entry->getExternalReporter());
            $sheet->setCellValue('L'.$lineNumber, $entry->getExternalSummary());
            $sheet->setCellValue('M'.$lineNumber, implode(', ', $entry->getExternalLabels()));
            if ($showBillableField) {
                $sheet->setCellValue('N'.$lineNumber, (int) ((bool) $entry->getBillable()));
            }
            if ($q->tickettitles) {
                $sheet->setCellValue('O'.$lineNumber, $entry->getTicketTitle());
            }
            ++$lineNumber;
        }

        $sheet = $spreadsheet->getSheet(2);
        $lineNumber = 2;
        ksort($stats);
        foreach ($stats as $user => $userStats) {
            $sheet->setCellValue('A'.$lineNumber, $user);
            $sheet->setCellValue('B'.$lineNumber, $q->month);
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

    protected static function setCellDate(Worksheet $worksheet, string $column, int $row, \DateTimeInterface $date, string $format = \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD)
    {
        $worksheet->setCellValue(
            $column.$row,
            \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date)
        );
        $worksheet->getStyle($column.$row)
            ->getNumberFormat()
            ->setFormatCode($format);
    }

    protected static function setCellHours(Worksheet $worksheet, string $column, int $row, \DateTimeInterface $date)
    {
        $dateValue = (float) \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date);
        $hourValue = $dateValue - floor($dateValue);
        $worksheet->setCellValue($column.$row, $hourValue);
        $worksheet->getStyle($column.$row)
            ->getNumberFormat()
            ->setFormatCode('HH:MM');
    }
}


