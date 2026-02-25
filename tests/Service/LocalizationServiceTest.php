<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Util\LocalizationService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class LocalizationServiceTest extends TestCase
{
    public function testNormalizeLocalePassThrough(): void
    {
        $localizationService = new LocalizationService();
        self::assertSame('de', $localizationService->normalizeLocale('de'));
        self::assertSame('en', $localizationService->normalizeLocale('EN'));
        self::assertSame('fr', $localizationService->normalizeLocale(' fr '));
    }

    public function testNormalizeLocaleFallsBackToPreferred(): void
    {
        $localizationService = new LocalizationService();
        self::assertSame('en', $localizationService->normalizeLocale('pt'));
        self::assertSame('en', $localizationService->normalizeLocale(''));
    }
}
