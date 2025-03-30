<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Helper;

/**
 * Helper for anything related to localization
 */
class LocalizationHelper
{
    public static function getAvailableLocales(): array
    {
        return [
            'de' => 'German',
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'ru' => 'Russian',
        ];
    }

    public static function getPreferredLocale(): string
    {
        return 'en';
    }

    /**
     * @param $locale
     */
    public static function normalizeLocale($locale): string
    {
        $locale = strtolower(trim((string) $locale));
        if (array_key_exists($locale, self::getAvailableLocales())) {
            return $locale;
        }

        return self::getPreferredLocale();
    }
}
