<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Extension;

use Twig\Attribute\AsTwigFilter;

use function in_array;

/**
 * Class TwigCsvEscapingExtension.
 */
class TwigCsvEscapingExtension
{
    public function getName(): string
    {
        return 'csv_escaper';
    }

    /**
     * Characters that spreadsheet applications interpret as a formula trigger
     * when they appear at the start of a cell (OWASP CSV injection).
     */
    private const FORMULA_TRIGGER_CHARACTERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    #[AsTwigFilter(name: 'csv_escape')]
    public function csvEscape(string $string): string
    {
        $escaped = str_replace('"', '""', $string);

        // Neutralize formula injection: prefix dangerous leading characters
        // with a single quote so Excel/LibreOffice treat the cell as text.
        // See https://owasp.org/www-community/attacks/CSV_Injection
        if ('' !== $escaped && in_array($escaped[0], self::FORMULA_TRIGGER_CHARACTERS, true)) {
            return "'" . $escaped;
        }

        return $escaped;
    }
}
