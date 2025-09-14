<?php

declare(strict_types=1);

namespace App\Controller\Controlling;

use App\Controller\BaseController;
use App\Dto\ExportQueryDto;
use App\Entity\Entry;
use App\Model\Response;
use App\Service\ExportService as Export;
use App\Util\PhpSpreadsheet\LOReadFilter;
use DateTimeInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;

use function is_scalar;

use const STR_PAD_LEFT;

final class ExportAction extends BaseController
{
    private Export $export;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setExportService(Export $export): void
    {
        $this->export = $export;
    }

    /**
     * @throws InvalidArgumentException When export parameters are invalid or file operations fail
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/controlling/export', name: '_controllingExport_attr_invokable', methods: ['GET'])]
    #[\Symfony\Component\Routing\Attribute\Route(path: '/controlling/export/{userid}/{year}/{month}/{project}/{customer}/{billable}', name: '_controllingExport_bc', methods: ['GET'], requirements: ['year' => '\d+', 'userid' => '\d+'], defaults: ['userid' => 0, 'year' => 0, 'month' => 0, 'project' => 0, 'customer' => 0, 'billable' => 0])]
    public function __invoke(Request $request, #[MapQueryString] ExportQueryDto $exportQueryDto): Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        // Map legacy path parameters to query parameters for backward compatibility
        $attributeKeysToMap = ['project', 'userid', 'year', 'month', 'customer', 'billable'];
        foreach ($attributeKeysToMap as $attributeKeyToMap) {
            if ($request->attributes->has($attributeKeyToMap) && !$request->query->has($attributeKeyToMap)) {
                /** @var mixed $attributeValue */
                $attributeValue = $request->attributes->get($attributeKeyToMap);
                $stringValue = is_scalar($attributeValue) ? (string) $attributeValue : '';
                $request->query->set($attributeKeyToMap, $stringValue);
            }
        }

        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        // Validate month parameter
        if ($exportQueryDto->month < 0 || $exportQueryDto->month > 12) {
            return new Response('Month must be between 0 and 12 (0 means all months)', \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Validate year parameter (reasonable range)
        if ($exportQueryDto->year < 1900 || $exportQueryDto->year > 2100) {
            return new Response('Year must be between 1900 and 2100', \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $service = $this->export;
        /** @var array<int, Entry> $entries */
        $entries = $service->exportEntries(
            $exportQueryDto->userid,
            $exportQueryDto->year,
            $exportQueryDto->month,
            $exportQueryDto->project,
            $exportQueryDto->customer,
            [
                'user.username' => 'ASC',
                'entry.day' => 'DESC',
                'entry.start' => 'DESC',
            ],
        );

        $showBillableField = $this->params->has('app_show_billable_field_in_export')
            && (bool) $this->params->get('app_show_billable_field_in_export');
        if ($showBillableField || $exportQueryDto->tickettitles) {
            $entries = $service->enrichEntriesWithTicketInformation(
                $this->getUserId($request),
                $entries,
                $showBillableField,
                $exportQueryDto->billable,
                $exportQueryDto->tickettitles,
            );
        }

        $username = (string) $service->getUsername($exportQueryDto->userid);

        $filename = strtolower(
            $exportQueryDto->year . '_'
            . str_pad((string) $exportQueryDto->month, 2, '0', STR_PAD_LEFT)
            . '_'
            . str_replace(' ', '-', $username),
        );

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        // Apply LibreOffice column limit filter (prevents >1024 column errors)
        $reader->setReadFilter(new LOReadFilter());

        $spreadsheet = $reader->load(
            $this->kernel->getProjectDir() . '/public/template.xlsx',
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

        if ($exportQueryDto->tickettitles) {
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
            if (null !== $activity) {
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

            if ($entry->getDay() instanceof DateTimeInterface) {
                self::setCellDate($sheet, 'A', $lineNumber, $entry->getDay());
            }

            if ($entry->getStart() instanceof DateTimeInterface) {
                self::setCellHours($sheet, 'B', $lineNumber, $entry->getStart());
            }

            if ($entry->getEnd() instanceof DateTimeInterface) {
                self::setCellHours($sheet, 'C', $lineNumber, $entry->getEnd());
            }

            $customerName = '';
            $customerEntity = $entry->getCustomer();
            if ($customerEntity instanceof \App\Entity\Customer) {
                $customerName = (string) $customerEntity->getName();
            } else {
                $projectEntity = $entry->getProject();
                if ($projectEntity instanceof \App\Entity\Project && $projectEntity->getCustomer() instanceof \App\Entity\Customer) {
                    $customerName = (string) $projectEntity->getCustomer()->getName();
                }
            }

            $sheet->setCellValue('D' . $lineNumber, $customerName);

            $projectName = '';
            $projectEntity = $entry->getProject();
            if ($projectEntity instanceof \App\Entity\Project) {
                $projectName = $projectEntity->getName();
            }

            $sheet->setCellValue('E' . $lineNumber, $projectName);
            $sheet->setCellValue('F' . $lineNumber, $activity);
            $sheet->setCellValue('G' . $lineNumber, $entry->getDescription());
            $sheet->setCellValue('H' . $lineNumber, $entry->getTicket());
            $sheet->setCellValue('I' . $lineNumber, '=C' . $lineNumber . '-B' . $lineNumber);
            $sheet->setCellValue('J' . $lineNumber, $abbr);
            $sheet->setCellValue('K' . $lineNumber, $entry->getExternalReporter());
            $sheet->setCellValue('L' . $lineNumber, $entry->getExternalSummary());
            $sheet->setCellValue('M' . $lineNumber, implode(', ', $entry->getExternalLabels()));
            if ($showBillableField) {
                $sheet->setCellValue('N' . $lineNumber, (int) ((bool) $entry->getBillable()));
            }

            if ($exportQueryDto->tickettitles) {
                $sheet->setCellValue('O' . $lineNumber, $entry->getTicketTitle());
            }

            ++$lineNumber;
        }

        $sheet = $spreadsheet->getSheet(2);
        $lineNumber = 2;
        ksort($stats);
        foreach ($stats as $user => $userStats) {
            $sheet->setCellValue('A' . $lineNumber, $user);
            $sheet->setCellValue('B' . $lineNumber, $exportQueryDto->month);
            $sheet->setCellValue('D' . $lineNumber, '=SUMIF(ZE!$J$1:$J$5000,A' . $lineNumber . ',ZE!$I$1:$I$5000)');
            $sheet->getStyle('D' . $lineNumber)
                ->getNumberFormat()
                ->setFormatCode('[HH]:MM')
            ;
            if ($userStats['holidays'] > 0) {
                $sheet->setCellValue('E' . $lineNumber, $userStats['holidays']);
            }

            if ($userStats['sickdays'] > 0) {
                $sheet->setCellValue('F' . $lineNumber, $userStats['sickdays']);
            }

            ++$lineNumber;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ttt-export-');
        $filePath = false !== $tmp
            ? $tmp
            : $this->kernel->getProjectDir() . '/var/tmp/' . uniqid('ttt-export-', true) . '.xlsx';

        $xlsx = new Xlsx($spreadsheet);
        $xlsx->save($filePath);

        $response = new Response();
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-disposition', 'attachment;filename=' . $filename . '.xlsx');

        $content = file_get_contents($filePath) ?: '';
        $response->setContent($content);
        unlink($filePath);

        return $response;
    }

    protected static function setCellDate(Worksheet $worksheet, string $column, int $row, DateTimeInterface $date, string $format = \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD): void
    {
        $worksheet->setCellValue(
            $column . $row,
            \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date),
        );
        $worksheet->getStyle($column . $row)
            ->getNumberFormat()
            ->setFormatCode($format)
        ;
    }

    protected static function setCellHours(Worksheet $worksheet, string $column, int $row, DateTimeInterface $date): void
    {
        $dateValue = (float) \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date);
        $hourValue = $dateValue - floor($dateValue);
        $worksheet->setCellValue($column . $row, $hourValue);
        $worksheet->getStyle($column . $row)
            ->getNumberFormat()
            ->setFormatCode('HH:MM')
        ;
    }
}
