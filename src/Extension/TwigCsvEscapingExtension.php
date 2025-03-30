<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Extension;

use \Twig\Extension\AbstractExtension;

/**
 * Class TwigCsvEscapingExtension
 * @package App\Extension
 */
class TwigCsvEscapingExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'csv_escaper';
    }

    /**
     * @return \Twig\TwigFilter[]
     */
    public function getFilters()
    {
        return [
            new \Twig\TwigFilter('csv_escape', $this->csvEscape(...)),
        ];
    }

    /**
     * @param $string
     * @return string
     */
    public function csvEscape($string): string|array
    {
        return str_replace('"', '""', $string);
    }
}
