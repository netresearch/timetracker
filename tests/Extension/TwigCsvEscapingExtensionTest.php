<?php

declare(strict_types=1);

namespace Tests\Extension;

use App\Extension\TwigCsvEscapingExtension;
use PHPUnit\Framework\TestCase;

class TwigCsvEscapingExtensionTest extends TestCase
{
    public function testCsvEscape(): void
    {
        $twigCsvEscapingExtension = new TwigCsvEscapingExtension();
        $this->assertSame('Hello', $twigCsvEscapingExtension->csvEscape('Hello'));
        $this->assertSame('He said ""Hi""', $twigCsvEscapingExtension->csvEscape('He said "Hi"'));
        $this->assertSame('""Quoted""', $twigCsvEscapingExtension->csvEscape('"Quoted"'));
    }
}
