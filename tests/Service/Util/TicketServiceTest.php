<?php

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Service\Util\TicketService;
use PHPUnit\Framework\TestCase;

class TicketServiceTest extends TestCase
{
    /**
     * @dataProvider checkTicketFormatDataProvider
     */
    public function testCheckTicketFormat(bool $expected, string $ticket): void
    {
        $ticketService = new TicketService();
        $this->assertSame($expected, $ticketService->checkFormat($ticket));
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
            [true, 'OGN-1'],
        ];
    }

    /**
     * @dataProvider prefixDataProvider
     */
    public function testGetPrefix(?string $expectedPrefix, string $ticket): void
    {
        $ticketService = new TicketService();
        $this->assertSame($expectedPrefix, $ticketService->getPrefix($ticket));
    }

    public function prefixDataProvider(): array
    {
        return [
            [null, ''],
            [null, 'ABC'],
            ['ABC', 'ABC-1234'],
            ['OGN', 'OGN-1'],
            ['TTT2', 'TTT2-999'],
        ];
    }
}


