<?php

namespace Tests\Helper;

use App\Helper\TicketHelper;
use Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TicketHelperTest extends AbstractWebTestCase
{
    #[DataProvider('checkTicketFormatDataProvider')]
    public function testCheckTicketFormat(bool $value, string $ticket): void
    {
        $this->assertEquals($value, TicketHelper::checkFormat($ticket));
    }

    public function checkTicketFormatDataProvider(): array
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
            [true, 'OGN-1']
        ];
    }
}
