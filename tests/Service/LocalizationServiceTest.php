<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Util\LocalizationService;
use PHPUnit\Framework\TestCase;

class LocalizationServiceTest extends TestCase
{
    public function testNormalizeLocalePassThrough(): void
    {
        $svc = new LocalizationService();
        $this->assertSame('de', $svc->normalizeLocale('de'));
        $this->assertSame('en', $svc->normalizeLocale('EN'));
        $this->assertSame('fr', $svc->normalizeLocale(' fr '));
    }

    public function testNormalizeLocaleFallsBackToPreferred(): void
    {
        $svc = new LocalizationService();
        $this->assertSame('en', $svc->normalizeLocale('pt'));
        $this->assertSame('en', $svc->normalizeLocale(''));
    }
}


