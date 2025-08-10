<?php

declare(strict_types=1);

namespace Tests\Extension;

use App\Extension\TwigCsvEscapingExtension;
use PHPUnit\Framework\TestCase;

class TwigCsvEscapingExtensionTest extends TestCase
{
    public function testCsvEscape(): void
    {
        $ext = new TwigCsvEscapingExtension();
        $this->assertSame('Hello', $ext->csvEscape('Hello'));
        $this->assertSame('He said ""Hi""', $ext->csvEscape('He said "Hi"'));
        $this->assertSame('""Quoted""', $ext->csvEscape('"Quoted"'));
    }
}


