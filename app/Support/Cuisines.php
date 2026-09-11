<?php

namespace App\Support;

/**
 * The platform's cuisine taxonomy: the only tags a restaurant can carry, so
 * the app's cuisine filter never fragments into "Pizza" / "pizzeria" /
 * "Italian". Slugs are stored; labels are for humans. Edit the list in
 * config/platform.php — menu extraction, the owner's Settings page, and the
 * API all read it from there.
 */
class Cuisines
{
    /**
     * @return array<string, string> slug => label
     */
    public static function all(): array
    {
        return (array) config('platform.cuisines', []);
    }

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function isValid(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    public static function label(string $slug): string
    {
        return self::all()[$slug] ?? $slug;
    }

    /**
     * Keep only known slugs, de-duplicated and in the order given.
     *
     * @param  array<int, mixed>  $tags
     * @return array<int, string>
     */
    public static function filter(array $tags, int $max = 5): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $slug = strtolower(trim($tag));

            if (self::isValid($slug) && ! in_array($slug, $clean, true)) {
                $clean[] = $slug;
            }

            if (count($clean) >= $max) {
                break;
            }
        }

        return $clean;
    }
}
