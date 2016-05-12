<?php
namespace Netresearch\TimeTrackerBundle\Extension;

use \Twig_Extension as Extension;
use \Twig_Filter_Method as Method;

class TwigCsvEscapingExtension extends Extension
{
    public function getName()
    {
        return 'csv_escaper';
    }

    public function getFilters()
    {
        return array('csv_escape' => new Method($this, 'csvEscape'));
    }

    public function csvEscape($string)
    {
        return str_replace('"', '""', $string);
    }
}
