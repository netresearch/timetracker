<?php

namespace Netresearch\TimeTrackerBundle\Helper;

/*
 * Helper for anything related to localization
 */
class LocalizationHelper
{

    public static function getAvailableLocales()
    {
        return array(
            'de'    => 'German',
            'en'    => 'English',
            'es'    => 'Spanish',
            'fr'    => 'French',
            'ru'    => 'Russian'
        );
    }

    public static function getPreferredLocale()
    {
        return 'en';
    }

    public static function normalizeLocale($locale)
    {
        $locale = strtolower(trim($locale));
        if (array_key_exists($locale, self::getAvailableLocales()))
            return $locale;

        return self::getPreferredLocale();
    }

}

