<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hjelpere for <title> og meta-beskrivelse i layouts/app.blade.php.
 *
 * Bing flagger <title> over 70 tegn («Title too long»). title() dropper først
 * merkenavn-suffikset (« | Merke» / « – Merke» / « - Merke», også med TLD),
 * og er det fortsatt for langt, kuttes det ved et ordskille med «…».
 * og:title og twitter:title bruker hele tittelen.
 */
final class Seo
{
    public const MAX_TITLE = 70;

    /**
     * Inline @section('title', $x) escaper allerede verdien, og {{ }} i layouten
     * escaper igjen («&» ble «&amp;amp;»). Dekod én gang, så {{ }} escaper
     * nøyaktig én gang uansett hvordan siden satte seksjonen.
     */
    public static function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function title(string $title, ?string $brand = null, int $max = self::MAX_TITLE): string
    {
        $title = trim($title);

        if (mb_strlen($title) <= $max) {
            return $title;
        }

        $brand ??= (string) config('app.name');
        if ($brand !== '') {
            $title = (string) preg_replace(
                '/\s+[|–-]\s+'.preg_quote($brand, '/').'(\.[a-z]{2,})?$/ui',
                '',
                $title,
            );

            if (mb_strlen($title) <= $max) {
                return $title;
            }
        }

        $cut = mb_substr($title, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.:;–-|').'…';
    }
}
