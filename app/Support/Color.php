<?php

namespace App\Support;

/**
 * Calculs sur les couleurs hexadécimales du thème (#RRGGBB) : contraste WCAG, assombrissement, transparence.
 */
final class Color
{
    public static function isHex(?string $color): bool
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }

    /** Rapport de contraste WCAG 2.x entre deux couleurs (de 1 à 21). */
    public static function contrast(string $first, string $second): float
    {
        [$lighter, $darker] = [max(self::luminance($first), self::luminance($second)), min(self::luminance($first), self::luminance($second))];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** Même teinte assombrie (0.2 = 20 % plus sombre), pour le survol des boutons et liens. */
    public static function darken(string $color, float $amount): string
    {
        return '#'.implode('', array_map(fn (int $channel): string => sprintf('%02X', (int) round($channel * (1 - $amount))), self::channels($color)));
    }

    /** Valeur CSS rgba() de la couleur avec l'opacité donnée. */
    public static function rgba(string $color, float $alpha): string
    {
        return 'rgba('.implode(', ', self::channels($color)).', '.$alpha.')';
    }

    /** Composantes « R, G, B » (pour les variables --tblr-*-rgb). */
    public static function rgb(string $color): string
    {
        return implode(', ', self::channels($color));
    }

    private static function luminance(string $color): float
    {
        [$red, $green, $blue] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::channels($color));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function channels(string $color): array
    {
        return array_map('hexdec', str_split(ltrim($color, '#'), 2));
    }
}
