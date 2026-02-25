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

/**
 * Class TwigCsvEscapingExtension.
 */
class TwigCsvEscapingExtension
{
    public function getName(): string
    {
        return 'csv_escaper';
    }

    #[AsTwigFilter(name: 'csv_escape')]
    public function csvEscape(string $string): string
    {
        return str_replace('"', '""', $string);
    }
}
