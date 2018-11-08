<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace Netresearch\TimeTrackerBundle\Helper;

/**
 * Helper for anything related to localization
 */
class LocalizationHelper
{

    /**
     * @return array
     */
    public static function getAvailableLocales()
    {
        return [
            'de' => 'German',
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'ru' => 'Russian',
        ];
    }

    /**
     * @return string
     */
    public static function getPreferredLocale()
    {
        return 'en';
    }

    /**
     * @param $locale
     * @return string
     */
    public static function normalizeLocale($locale)
    {
        $locale = strtolower(trim($locale));
        if (array_key_exists($locale, self::getAvailableLocales())) {
            return $locale;
        }

        return self::getPreferredLocale();
    }

}

