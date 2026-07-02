<?php

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Service\Util\IcalHolidayParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the minimal holiday-feed iCal parser.
 *
 * @internal
 */
#[CoversClass(IcalHolidayParser::class)]
final class IcalHolidayParserTest extends TestCase
{
    private IcalHolidayParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IcalHolidayParser();
    }

    public function testParsesAllDayEvents(): void
    {
        $ical = "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20260101\r\nSUMMARY:Neujahr\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261225\r\nSUMMARY:1. Weihnachtstag\r\nEND:VEVENT\r\n"
            . 'END:VCALENDAR';

        self::assertSame([
            '2026-01-01' => 'Neujahr',
            '2026-12-25' => '1. Weihnachtstag',
        ], $this->parser->parse($ical));
    }

    public function testParsesDatetimeStartForm(): void
    {
        $ical = "BEGIN:VEVENT\nDTSTART:20260403T000000Z\nSUMMARY:Karfreitag\nEND:VEVENT";

        self::assertSame(['2026-04-03' => 'Karfreitag'], $this->parser->parse($ical));
    }

    public function testUnfoldsContinuationLines(): void
    {
        // RFC 5545 line folding: CRLF + space continues the line.
        $ical = "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20260501\r\nSUMMARY:Tag der\r\n  Arbeit\r\nEND:VEVENT";

        self::assertSame(['2026-05-01' => 'Tag der Arbeit'], $this->parser->parse($ical));
    }

    public function testSortsEventsByDate(): void
    {
        $ical = "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20261225\nSUMMARY:Later\nEND:VEVENT\n"
            . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20260101\nSUMMARY:Earlier\nEND:VEVENT";

        self::assertSame(['2026-01-01', '2026-12-25'], array_keys($this->parser->parse($ical)));
    }

    public function testIgnoresEventsWithoutDateOrSummary(): void
    {
        $ical = "BEGIN:VEVENT\nSUMMARY:No date\nEND:VEVENT\n"
            . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20260101\nEND:VEVENT\n"
            . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:invalid\nSUMMARY:Bad date\nEND:VEVENT";

        self::assertSame([], $this->parser->parse($ical));
    }

    public function testIgnoresPropertiesOutsideEvents(): void
    {
        $ical = "DTSTART;VALUE=DATE:20260101\nSUMMARY:Stray\n"
            . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20260606\nSUMMARY:Real\nEND:VEVENT";

        self::assertSame(['2026-06-06' => 'Real'], $this->parser->parse($ical));
    }

    public function testEmptyInputYieldsNoEvents(): void
    {
        self::assertSame([], $this->parser->parse(''));
    }
}
