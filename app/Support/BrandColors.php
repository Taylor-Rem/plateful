<?php

namespace App\Support;

class BrandColors
{
    public const FALLBACK_PRIMARY = '#171717';

    public const FALLBACK_SECONDARY = '#171717';

    public const LIGHT_TEXT = '#ffffff';

    public const DARK_TEXT = '#0a0a0a';

    /**
     * Normalize a hex color string, returning the fallback if invalid/empty.
     */
    public static function normalize(?string $color, string $fallback): string
    {
        if ($color === null || $color === '') {
            return $fallback;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            return $fallback;
        }

        return strtolower($color);
    }

    /**
     * Return a readable text color (#ffffff or #0a0a0a) for a given background hex,
     * based on the WCAG relative luminance formula.
     */
    public static function readableTextColor(string $hex): string
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $m) !== 1) {
            return self::LIGHT_TEXT;
        }

        $r = hexdec(substr($m[1], 0, 2)) / 255;
        $g = hexdec(substr($m[1], 2, 2)) / 255;
        $b = hexdec(substr($m[1], 4, 2)) / 255;

        $channel = static function (float $c): float {
            return $c <= 0.03928
                ? $c / 12.92
                : (($c + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);

        return $luminance > 0.5 ? self::DARK_TEXT : self::LIGHT_TEXT;
    }

    /**
     * Build the brand color CSS custom properties payload for a restaurant.
     *
     * @return array{primary: string, primaryForeground: string, secondary: string, secondaryForeground: string}
     */
    public static function paletteFor(?string $primary, ?string $secondary): array
    {
        $p = self::normalize($primary, self::FALLBACK_PRIMARY);
        $s = self::normalize($secondary, self::FALLBACK_SECONDARY);

        return [
            'primary' => $p,
            'primaryForeground' => self::readableTextColor($p),
            'secondary' => $s,
            'secondaryForeground' => self::readableTextColor($s),
        ];
    }
}
