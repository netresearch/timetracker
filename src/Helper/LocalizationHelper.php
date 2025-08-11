<?php

/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Helper;

use App\Service\Util\LocalizationService;

/**
 * Helper for anything related to localization (BC facade to LocalizationService)
 */
class LocalizationHelper
{
    public static function getAvailableLocales(): array
    {
        return (new LocalizationService())->getAvailableLocales();
    }

    public static function getPreferredLocale(): string
    {
        return (new LocalizationService())->getPreferredLocale();
    }

    public static function normalizeLocale($locale): string
    {
        return (new LocalizationService())->normalizeLocale((string) $locale);
    }
}
