<?php
namespace Netresearch\TimeTrackerBundle\Extension;

use \Twig_Extension as Extension;

class TwigCsvEscapingExtension extends Extension
{
    public function getName()
    {
        return 'csv_escaper';
    }

    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('csv_escape', array($this, 'csvEscape')),
        );
    }

    public function csvEscape($string)
    {
        return str_replace('"', '""', $string);
    }
}
