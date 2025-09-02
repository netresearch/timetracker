<?php

declare(strict_types=1);

namespace Tests\Extension;

use App\Extension\TwigCsvEscapingExtension;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class TwigCsvEscapingExtensionTest extends TestCase
{
    public function testCsvEscape(): void
    {
        $twigCsvEscapingExtension = new TwigCsvEscapingExtension();
        self::assertSame('Hello', $twigCsvEscapingExtension->csvEscape('Hello'));
        self::assertSame('He said ""Hi""', $twigCsvEscapingExtension->csvEscape('He said "Hi"'));
        self::assertSame('""Quoted""', $twigCsvEscapingExtension->csvEscape('"Quoted"'));
    }
}
