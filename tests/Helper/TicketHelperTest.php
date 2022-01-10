<?php declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\TicketHelper;
use PHPUnit\Framework\TestCase;

class TicketHelperTest extends TestCase
{
    /**
     * @dataProvider checkTicketFormatDataProvider
     */
    public function testCheckTicketFormat($value, $ticket): void
    {
        static::assertSame($value, TicketHelper::checkFormat($ticket));
    }

    public function checkTicketFormatDataProvider()
    {
        return [
            [false, ''],
            [false, '-'],
            [false, '#1'],
            [false, 'ABC'],
            [false, 'ABC-A'],
            [false, '1234'],
            [false, '-1234'],
            [false, 'ABC-12-34'],

            [true, 'ABC-1234'],
            [true, 'ABC-1234567'],
            [true, 'OGN-1'],
        ];
    }

}
