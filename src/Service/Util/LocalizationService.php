<?php
declare(strict_types=1);

namespace App\Service\Util;

class LocalizationService
{
    /**
     * @return string[]
     *
     * @psalm-return array{de: 'German', en: 'English', es: 'Spanish', fr: 'French', ru: 'Russian'}
     */
    public function getAvailableLocales(): array
    {
        return [
            'de' => 'German',
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'ru' => 'Russian',
        ];
    }

    public function getPreferredLocale(): string
    {
        return 'en';
    }

    public function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if (array_key_exists($locale, $this->getAvailableLocales())) {
            return $locale;
        }

        return $this->getPreferredLocale();
    }
}
