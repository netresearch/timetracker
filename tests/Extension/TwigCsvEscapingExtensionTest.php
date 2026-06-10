<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Extension;

use App\Extension\TwigCsvEscapingExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ==================== formula injection tests ====================

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideFormulaTriggerCases(): array
    {
        return [
            'equals sign' => ['=1+2', "'=1+2"],
            'plus sign' => ['+SUM(A1:A2)', "'+SUM(A1:A2)"],
            'minus sign' => ['-2+3', "'-2+3"],
            'at sign' => ['@cmd', "'@cmd"],
            'tab' => ["\t=1+2", "'\t=1+2"],
            'carriage return' => ["\r=1+2", "'\r=1+2"],
            'line feed' => ["\n=1+2", "'\n=1+2"],
        ];
    }

    #[DataProvider('provideFormulaTriggerCases')]
    public function testCsvEscapePrefixesFormulaTriggerCharacters(string $input, string $expected): void
    {
        self::assertSame($expected, $this->extension->csvEscape($input));
    }

    public function testCsvEscapeCombinesFormulaPrefixWithQuoteDoubling(): void
    {
        self::assertSame("'=HYPERLINK(\"\"https://evil.example\"\")", $this->extension->csvEscape('=HYPERLINK("https://evil.example")'));
    }

    public function testCsvEscapeLeavesFormulaCharactersInsideStringUnchanged(): void
    {
        self::assertSame('a=b', $this->extension->csvEscape('a=b'));
        self::assertSame('1 + 2', $this->extension->csvEscape('1 + 2'));
    }
}
