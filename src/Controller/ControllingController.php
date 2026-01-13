<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Back-compat shim for tests that reference protected static spreadsheet helpers.
 * Routes are handled by App\Controller\Controlling\ExportAction.
 */
class ControllingController extends BaseController
{
    /**
     * Set cell value to numeric date value and given display format.
     */
    protected static function setCellDate(
        Worksheet $worksheet,
        string $column,
        int $row,
        DateTimeInterface $date,
        string $format = NumberFormat::FORMAT_DATE_YYYYMMDD,
    ): void {
        $worksheet->setCellValue(
            $column . $row,
            Date::PHPToExcel($date),
        );
        $worksheet->getStyle($column . $row)
            ->getNumberFormat()
            ->setFormatCode($format);
    }

    /**
     * Set cell value to a numeric time value and display format to HH:MM.
     */
    protected static function setCellHours(
        Worksheet $worksheet,
        string $column,
        int $row,
        DateTimeInterface $date,
    ): void {
        $dateValue = (float) Date::PHPToExcel($date);
        $hourValue = $dateValue - floor($dateValue);
        $worksheet->setCellValue($column . $row, $hourValue);
        $worksheet->getStyle($column . $row)
            ->getNumberFormat()
            ->setFormatCode('HH:MM');
    }
}
