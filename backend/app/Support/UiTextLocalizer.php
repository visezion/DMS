<?php

namespace App\Support;

class UiTextLocalizer
{
    public function translateHtml(string $html, string $locale): string
    {
        $locale = LocaleManager::normalize($locale);
        if ($locale === 'en') {
            return $html;
        }

        $path = lang_path($locale.DIRECTORY_SEPARATOR.'runtime.php');
        if (! is_file($path)) {
            return $html;
        }

        $map = require $path;
        if (! is_array($map) || $map === []) {
            return $html;
        }

        $protectedBlocks = [];
        $html = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\1>/is', function (array $matches) use (&$protectedBlocks): string {
            $token = '__DMS_LOCALIZER_BLOCK_'.count($protectedBlocks).'__';
            $protectedBlocks[$token] = $matches[0];

            return $token;
        }, $html) ?? $html;

        $segments = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($segments)) {
            return $this->restoreProtectedBlocks($html, $protectedBlocks);
        }

        foreach ($segments as $index => $segment) {
            if ($segment === '' || str_starts_with($segment, '<')) {
                continue;
            }

            $segments[$index] = strtr($segment, $map);
        }

        $translated = implode('', $segments);
        $translated = preg_replace_callback(
            '/\b(placeholder|title|aria-label|alt)=("([^"]*)"|\'([^\']*)\')/i',
            static function (array $matches) use ($map): string {
                $attribute = $matches[1];
                $quote = $matches[2][0];
                $value = $matches[3] !== '' ? $matches[3] : $matches[4];

                return $attribute.'='.$quote.strtr($value, $map).$quote;
            },
            $translated
        ) ?? $translated;

        return $this->restoreProtectedBlocks($translated, $protectedBlocks);
    }

    /**
     * @param  array<string, string>  $protectedBlocks
     */
    protected function restoreProtectedBlocks(string $html, array $protectedBlocks): string
    {
        if ($protectedBlocks === []) {
            return $html;
        }

        return strtr($html, $protectedBlocks);
    }
}
