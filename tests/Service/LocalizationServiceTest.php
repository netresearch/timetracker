<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Util\LocalizationService;
use PHPUnit\Framework\TestCase;

class LocalizationServiceTest extends TestCase
{
    public function testNormalizeLocalePassThrough(): void
    {
        $localizationService = new LocalizationService();
        $this->assertSame('de', $localizationService->normalizeLocale('de'));
        $this->assertSame('en', $localizationService->normalizeLocale('EN'));
        $this->assertSame('fr', $localizationService->normalizeLocale(' fr '));
    }

    public function testNormalizeLocaleFallsBackToPreferred(): void
    {
        $localizationService = new LocalizationService();
        $this->assertSame('en', $localizationService->normalizeLocale('pt'));
        $this->assertSame('en', $localizationService->normalizeLocale(''));
    }
}


