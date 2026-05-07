<?php

namespace App\Support;

class LocaleManager
{
    public static function supported(): array
    {
        $configured = config('dms.supported_locales', [
            'en' => 'English',
            'tr' => 'Turkce',
        ]);

        if (! is_array($configured) || $configured === []) {
            return [
                'en' => 'English',
                'tr' => 'Turkce',
            ];
        }

        return collect($configured)
            ->mapWithKeys(fn ($label, $locale) => [self::normalize((string) $locale) => (string) $label])
            ->all();
    }

    public static function isSupported(?string $locale): bool
    {
        return array_key_exists(self::normalize($locale), self::supported());
    }

    public static function normalize(?string $locale): string
    {
        $candidate = strtolower(trim((string) $locale));
        $candidate = str_replace('_', '-', $candidate);

        return match ($candidate) {
            'tr', 'tr-tr' => 'tr',
            'en', 'en-us', 'en-gb' => 'en',
            default => array_key_first(self::supported()) ?: 'en',
        };
    }
}
