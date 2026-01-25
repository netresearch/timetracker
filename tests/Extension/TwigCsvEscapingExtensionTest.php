<?php

declare(strict_types=1);

namespace Tests\Extension;

use App\Extension\TwigCsvEscapingExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TwigCsvEscapingExtension.
 *
 * @internal
 */
#[CoversClass(TwigCsvEscapingExtension::class)]
final class TwigCsvEscapingExtensionTest extends TestCase
{
    private TwigCsvEscapingExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new TwigCsvEscapingExtension();
    }

    // ==================== getName tests ====================

    public function testGetNameReturnsCsvEscaper(): void
    {
        self::assertSame('csv_escaper', $this->extension->getName());
    }

    // ==================== csvEscape tests ====================

    public function testCsvEscapeReturnsUnchangedStringWithoutQuotes(): void
    {
        self::assertSame('Hello', $this->extension->csvEscape('Hello'));
    }

    public function testCsvEscapeDoublesQuotesInMiddle(): void
    {
        self::assertSame('He said ""Hi""', $this->extension->csvEscape('He said "Hi"'));
    }

    public function testCsvEscapeDoublesQuotesAtBoundaries(): void
    {
        self::assertSame('""Quoted""', $this->extension->csvEscape('"Quoted"'));
    }

    public function testCsvEscapeHandlesEmptyString(): void
    {
        self::assertSame('', $this->extension->csvEscape(''));
    }

    public function testCsvEscapeHandlesOnlyQuotes(): void
    {
        self::assertSame('""', $this->extension->csvEscape('"'));
        self::assertSame('""""', $this->extension->csvEscape('""'));
    }

    public function testCsvEscapeHandlesMultipleQuotesInSequence(): void
    {
        self::assertSame('a""""b', $this->extension->csvEscape('a""b'));
    }
}
