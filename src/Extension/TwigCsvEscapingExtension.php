<?php

declare(strict_types=1);

/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Extension;

use Twig\Extension\AbstractExtension;

/**
 * Class TwigCsvEscapingExtension.
 */
class TwigCsvEscapingExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'csv_escaper';
    }

    /**
     * Returns a list of filters to add to the existing list.
     *
     * @return array<\Twig\TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new \Twig\TwigFilter('csv_escape', [$this, 'csvEscape']),
        ];
    }

    public function csvEscape(string $string): string
    {
        return str_replace('"', '""', $string);
    }
}
