<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Twig;

use Twig_SimpleFilter;
use Twig\Extension\AbstractExtension;

/**
 * Class TwigCsvEscapingExtension
 * @package App\Extension
 */
class TwigCsvEscapingExtension extends AbstractExtension
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'csv_escaper';
    }

    /**
     * @return \Twig_SimpleFilter[]
     */
    public function getFilters()
    {
        return array(
            new Twig_SimpleFilter('csv_escape', array($this, 'csvEscape')),
        );
    }

    /**
     * @param $string
     * @return string
     */
    public function csvEscape($string)
    {
        return str_replace('"', '""', $string);
    }
}
