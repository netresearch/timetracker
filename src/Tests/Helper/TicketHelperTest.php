<?php

namespace App\Tests\Helper;

require_once(dirname(__FILE__) . "/../../Helper/TicketHelper.php");

use App\Helper\TicketHelper;
use PHPUnit\Framework\TestCase;

class TicketHelperTest extends TestCase
{
    /**
     * @dataProvider checkTicketFormatDataProvider
     */
    public function testCheckTicketFormat($value, $ticket)
    {
        $this->assertEquals($value, TicketHelper::checkFormat($ticket));
    }

    public function checkTicketFormatDataProvider()
    {
        return array(
            array(false, ''),
            array(false, '-'),
            array(false, '#1'),
            array(false, 'ABC'),
            array(false, 'ABC-A'),
            array(false, '1234'),
            array(false, '-1234'),
            array(false, 'ABC-12-34'),

            array(true, 'ABC-1234'),
            array(true, 'ABC-1234567'),
            array(true, 'OGN-1')
        );
    }

}
