<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace Netresearch\TimeTrackerBundle\Extension;

use \Twig_Extension as Extension;

/**
 * Class TwigCsvEscapingExtension
 * @package Netresearch\TimeTrackerBundle\Extension
 */
class TwigCsvEscapingExtension extends Extension
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
            new \Twig_SimpleFilter('csv_escape', array($this, 'csvEscape')),
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
