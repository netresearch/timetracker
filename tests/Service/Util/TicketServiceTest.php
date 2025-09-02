<?php

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Service\Util\TicketService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class TicketServiceTest extends TestCase
{
    #[DataProvider('provideCheckTicketFormatCases')]
    public function testCheckTicketFormat(bool $expected, string $ticket): void
    {
        $ticketService = new TicketService();
        self::assertSame($expected, $ticketService->checkFormat($ticket));
    }

    public static function provideCheckTicketFormatCases(): iterable
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

    #[DataProvider('provideGetPrefixCases')]
    public function testGetPrefix(?string $expectedPrefix, string $ticket): void
    {
        $ticketService = new TicketService();
        self::assertSame($expectedPrefix, $ticketService->getPrefix($ticket));
    }

    public static function provideGetPrefixCases(): iterable
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
