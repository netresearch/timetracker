<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Controlling;

use App\Controller\BaseController;
use App\Dto\ExportQueryDto;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Model\Response;
use App\Service\ExportService as Export;
use App\Util\PhpSpreadsheet\LOReadFilter;
use DateTimeInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function is_scalar;

use const STR_PAD_LEFT;

final class ExportAction extends BaseController
{
    private Export $export;

    #[Required]
    public function setExportService(Export $export): void
    {
        $this->export = $export;
    }

    /**
     * @throws InvalidArgumentException When export parameters are invalid or file operations fail
     */
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route(path: '/controlling/export', name: '_controllingExport_attr_invokable', methods: ['GET'])]
    #[Route(path: '/controlling/export/{userid}/{year}/{month}/{project}/{customer}/{billable}', name: '_controllingExport_bc', requirements: ['year' => '\d+', 'userid' => '\d+'], defaults: ['userid' => 0, 'year' => 0, 'month' => 0, 'project' => 0, 'customer' => 0, 'billable' => 0], methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Map legacy path parameters to query parameters for backward compatibility
        // This must happen BEFORE creating the DTO (hence we can't use #[MapQueryString])
        $this->mapLegacyPathParameters($request);

        // Create DTO from query params (which now includes mapped path params)
        $exportQueryDto = ExportQueryDto::fromRequest($request);

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

        $reader = IOFactory::createReader('Xlsx');
        // Apply LibreOffice column limit filter (prevents >1024 column errors)
        $reader->setReadFilter(new LOReadFilter());

        $spreadsheet = $reader->load(
            $this->kernel->getProjectDir() . '/public/template.xlsx',
        );

        $sheet = $spreadsheet->getSheet(0);

        $this->writeHeadings($sheet, $showBillableField, $exportQueryDto->tickettitles);

        $lineNumber = 3;
        $stats = [];
        foreach ($entries as $entry) {
            $abbr = null !== $entry->getUser() ? (string) $entry->getUser()->getAbbr() : '';
            $activityName = $this->collectEntryStats($stats, $abbr, $entry->getActivity());

            $this->writeEntryRow($sheet, $lineNumber, $entry, $abbr, $activityName, $showBillableField, $exportQueryDto->tickettitles);

            ++$lineNumber;
        }

        $this->writeStatsSheet($spreadsheet->getSheet(2), $stats, $exportQueryDto->month);

        return $this->buildXlsxResponse($spreadsheet, $filename);
    }

    private function mapLegacyPathParameters(Request $request): void
    {
        $attributeKeysToMap = ['project', 'userid', 'year', 'month', 'customer', 'billable'];
        foreach ($attributeKeysToMap as $attributeKeyToMap) {
            if ($request->attributes->has($attributeKeyToMap) && !$request->query->has($attributeKeyToMap)) {
                $attributeValue = $request->attributes->get($attributeKeyToMap);
                $stringValue = is_scalar($attributeValue) ? (string) $attributeValue : '';
                $request->query->set($attributeKeyToMap, $stringValue);
            }
        }
    }

    private function writeHeadings(Worksheet $worksheet, bool $showBillableField, bool $showTicketTitles): void
    {
        $headingStyle = [
            'font' => [
                'bold' => true,
            ],
        ];
        if ($showBillableField) {
            $worksheet->setCellValue('N2', 'billable');
            $worksheet->getStyle('N2')->applyFromArray($headingStyle);
        }

        if ($showTicketTitles) {
            $worksheet->setCellValue('O2', 'Tickettitel');
            $worksheet->getStyle('O2')->applyFromArray($headingStyle);
        }
    }

    /**
     * Counts holidays/sickdays per user abbreviation and resolves the activity name.
     *
     * @param array<string, array{holidays: int, sickdays: int}> $stats
     */
    private function collectEntryStats(array &$stats, string $abbr, ?Activity $activity): string
    {
        if (!isset($stats[$abbr])) {
            $stats[$abbr] = [
                'holidays' => 0,
                'sickdays' => 0,
            ];
        }

        if (!$activity instanceof Activity) {
            return ' ';
        }

        if ($activity->isHoliday()) {
            ++$stats[$abbr]['holidays'];
        }

        if ($activity->isSick()) {
            ++$stats[$abbr]['sickdays'];
        }

        return $activity->getName();
    }

    private function writeEntryRow(
        Worksheet $worksheet,
        int $lineNumber,
        Entry $entry,
        string $abbr,
        string $activityName,
        bool $showBillableField,
        bool $showTicketTitles,
    ): void {
        self::setCellDate($worksheet, 'A', $lineNumber, $entry->getDay());
        self::setCellHours($worksheet, 'B', $lineNumber, $entry->getStart());
        self::setCellHours($worksheet, 'C', $lineNumber, $entry->getEnd());

        $worksheet->setCellValue('D' . $lineNumber, $this->resolveCustomerName($entry));
        $worksheet->setCellValue('E' . $lineNumber, $this->resolveProjectName($entry));
        $worksheet->setCellValue('F' . $lineNumber, $activityName);
        $worksheet->setCellValue('G' . $lineNumber, $entry->getDescription());
        $worksheet->setCellValue('H' . $lineNumber, $entry->getTicket());
        $worksheet->setCellValue('I' . $lineNumber, '=C' . $lineNumber . '-B' . $lineNumber);
        $worksheet->setCellValue('J' . $lineNumber, $abbr);
        $worksheet->setCellValue('K' . $lineNumber, $entry->getExternalReporter());
        $worksheet->setCellValue('L' . $lineNumber, $entry->getExternalSummary());
        $worksheet->setCellValue('M' . $lineNumber, implode(', ', $entry->getExternalLabels()));
        if ($showBillableField) {
            $worksheet->setCellValue('N' . $lineNumber, (int) ((bool) $entry->getBillable()));
        }

        if ($showTicketTitles) {
            $worksheet->setCellValue('O' . $lineNumber, $entry->getTicketTitle());
        }
    }

    private function resolveCustomerName(Entry $entry): string
    {
        $customerEntity = $entry->getCustomer();
        if ($customerEntity instanceof Customer) {
            return (string) $customerEntity->getName();
        }

        $projectEntity = $entry->getProject();
        if ($projectEntity instanceof Project && $projectEntity->getCustomer() instanceof Customer) {
            return (string) $projectEntity->getCustomer()->getName();
        }

        return '';
    }

    private function resolveProjectName(Entry $entry): string
    {
        $projectEntity = $entry->getProject();

        return $projectEntity instanceof Project ? $projectEntity->getName() : '';
    }

    /**
     * @param array<string, array{holidays: int, sickdays: int}> $stats
     */
    private function writeStatsSheet(Worksheet $worksheet, array $stats, int $month): void
    {
        $lineNumber = 2;
        ksort($stats);
        foreach ($stats as $user => $userStats) {
            $worksheet->setCellValue('A' . $lineNumber, $user);
            $worksheet->setCellValue('B' . $lineNumber, $month);
            $worksheet->setCellValue('D' . $lineNumber, '=SUMIF(ZE!$J$1:$J$5000,A' . $lineNumber . ',ZE!$I$1:$I$5000)');
            $worksheet->getStyle('D' . $lineNumber)
                ->getNumberFormat()
                ->setFormatCode('[HH]:MM');
            if ($userStats['holidays'] > 0) {
                $worksheet->setCellValue('E' . $lineNumber, $userStats['holidays']);
            }

            if ($userStats['sickdays'] > 0) {
                $worksheet->setCellValue('F' . $lineNumber, $userStats['sickdays']);
            }

            ++$lineNumber;
        }
    }

    private function buildXlsxResponse(Spreadsheet $spreadsheet, string $filename): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ttt-export-');
        $filePath = false !== $tmp
            ? $tmp
            : $this->kernel->getProjectDir() . '/var/tmp/' . uniqid('ttt-export-', true) . '.xlsx';

        $xlsx = new Xlsx($spreadsheet);
        $xlsx->save($filePath);

        $response = new Response();
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-disposition', 'attachment;filename=' . $filename . '.xlsx');

        $fileContents = file_get_contents($filePath);
        $response->setContent(false !== $fileContents ? $fileContents : '');
        unlink($filePath);

        return $response;
    }

    protected static function setCellDate(Worksheet $worksheet, string $column, int $row, DateTimeInterface $date, string $format = NumberFormat::FORMAT_DATE_YYYYMMDD): void
    {
        $worksheet->setCellValue(
            $column . $row,
            Date::PHPToExcel($date),
        );
        $worksheet->getStyle($column . $row)
            ->getNumberFormat()
            ->setFormatCode($format);
    }

    protected static function setCellHours(Worksheet $worksheet, string $column, int $row, DateTimeInterface $date): void
    {
        $dateValue = (float) Date::PHPToExcel($date);
        $hourValue = $dateValue - floor($dateValue);
        $worksheet->setCellValue($column . $row, $hourValue);
        $worksheet->getStyle($column . $row)
            ->getNumberFormat()
            ->setFormatCode('HH:MM');
    }
}
