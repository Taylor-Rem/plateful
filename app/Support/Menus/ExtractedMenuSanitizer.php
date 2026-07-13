<?php

namespace App\Support\Menus;

use RuntimeException;

/**
 * Defense line between model output and the review screen: enforces caps and
 * bounds regardless of what the extraction returned, so a bad parse can never
 * flood a menu or smuggle absurd prices past a skimming owner.
 */
class ExtractedMenuSanitizer
{
    /**
     * @param  array<int, mixed>  $categories
     * @param  array<int, string>  $warnings
     * @return array{categories: array<int, array{name: string, items: array<int, array{name: string, description: ?string, price_cents: int, price_note: ?string}>}>, warnings: array<int, string>}
     */
    public static function sanitize(array $categories, array $warnings = []): array
    {
        $maxCategories = (int) config('menu_import.max_categories');
        $maxItems = (int) config('menu_import.max_items');
        $maxPrice = (int) config('menu_import.max_price_cents');

        $clean = [];
        $totalItems = 0;

        foreach (array_slice($categories, 0, $maxCategories) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $categoryName = self::cleanString($category['name'] ?? null, 80);
            if ($categoryName === null) {
                $categoryName = 'Menu';
            }

            $items = [];
            foreach ((array) ($category['items'] ?? []) as $item) {
                if (! is_array($item) || $totalItems >= $maxItems) {
                    continue;
                }

                $name = self::cleanString($item['name'] ?? null, 120);
                if ($name === null) {
                    continue;
                }

                $price = (int) ($item['price_cents'] ?? 0);
                $priceNote = self::cleanString($item['price_note'] ?? null, 200);

                if ($price < 0 || $price > $maxPrice) {
                    $priceNote = trim(sprintf(
                        'Extracted price looked wrong (%s) — please set it. %s',
                        number_format($price / 100, 2),
                        $priceNote ?? '',
                    ));
                    $price = 0;
                }

                $items[] = [
                    'name' => $name,
                    'description' => self::cleanString($item['description'] ?? null, 500),
                    'price_cents' => $price,
                    'price_note' => $priceNote,
                ];
                $totalItems++;
            }

            if ($items !== []) {
                $clean[] = ['name' => $categoryName, 'items' => $items];
            }
        }

        if ($totalItems === 0) {
            throw new RuntimeException('No menu items could be read from those files.');
        }

        if (count($categories) > $maxCategories || $totalItems >= $maxItems) {
            $warnings[] = 'The menu was very large — some entries may have been left out.';
        }

        return [
            'categories' => $clean,
            'warnings' => array_values(array_unique(array_filter($warnings))),
        ];
    }

    private static function cleanString(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
