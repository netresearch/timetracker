<?php

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Service\Util\TicketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TicketService.
 *
 * @internal
 */
#[CoversClass(TicketService::class)]
final class TicketServiceTest extends TestCase
{
    private TicketService $ticketService;

    protected function setUp(): void
    {
        $this->ticketService = new TicketService();
    }

    // ==================== checkFormat tests ====================

    #[DataProvider('provideCheckTicketFormatCases')]
    public function testCheckTicketFormat(bool $expected, string $ticket): void
    {
        self::assertSame($expected, $this->ticketService->checkFormat($ticket));
    }

    /**
     * @return iterable<array{bool, string}>
     */
    public static function provideCheckTicketFormatCases(): iterable
    {
        return [
            // Invalid formats
            [false, ''],
            [false, '-'],
            [false, '#1'],
            [false, 'ABC'],
            [false, 'ABC-A'],
            [false, '1234'],
            [false, '-1234'],
            [false, 'ABC-12-34'],
            [false, '123-456'],  // Must start with letter

            // Valid formats
            [true, 'ABC-1234'],
            [true, 'ABC-1234567'],
            [true, 'OGN-1'],
            [true, 'abc-123'],     // Case insensitive
            [true, 'A1-1'],        // Letter + number prefix
            [true, 'TEST2-99'],
        ];
    }

    // ==================== getPrefix tests ====================

    #[DataProvider('provideGetPrefixCases')]
    public function testGetPrefix(?string $expectedPrefix, string $ticket): void
    {
        self::assertSame($expectedPrefix, $this->ticketService->getPrefix($ticket));
    }

    /**
     * @return iterable<array{string|null, string}>
     */
    public static function provideGetPrefixCases(): iterable
    {
        return [
            [null, ''],
            [null, 'ABC'],
            [null, '123-456'],
            ['ABC', 'ABC-1234'],
            ['OGN', 'OGN-1'],
            ['TTT2', 'TTT2-999'],
            ['abc', 'abc-123'],  // Preserves original case
        ];
    }

    // ==================== extractJiraId tests ====================

    #[DataProvider('provideExtractJiraIdCases')]
    public function testExtractJiraId(string $expected, string $ticket): void
    {
        self::assertSame($expected, $this->ticketService->extractJiraId($ticket));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function provideExtractJiraIdCases(): iterable
    {
        return [
            // Returns empty string for invalid tickets
            ['', ''],
            ['', 'ABC'],
            ['', '123-456'],

            // Returns prefix for valid tickets
            ['JIRA', 'JIRA-123'],
            ['PROJECT', 'PROJECT-1'],
            ['T2', 'T2-999'],
        ];
    }
}
