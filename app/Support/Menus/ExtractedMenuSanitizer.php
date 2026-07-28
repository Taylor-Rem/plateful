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
     * @param  array<int, mixed>  $optionSets
     * @return array{categories: array<int, array{name: string, items: array<int, array{name: string, description: ?string, price_cents: int, price_note: ?string, option_set: ?string}>}>, option_sets: array<int, array{name: string, groups: array<int, array{name: string, min_selections: int, max_selections: ?int, options: array<int, array{name: string, price_delta_cents: int, is_default: bool}>}>}>, warnings: array<int, string>}
     */
    public static function sanitize(array $categories, array $warnings = [], array $optionSets = []): array
    {
        $maxCategories = (int) config('menu_import.max_categories');
        $maxItems = (int) config('menu_import.max_items');
        $maxPrice = (int) config('menu_import.max_price_cents');

        $cleanSets = self::sanitizeOptionSets($optionSets, $warnings);
        $setNames = array_column($cleanSets, 'name');

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

                $optionSet = self::cleanString($item['option_set'] ?? null, 80);
                if ($optionSet !== null && ! in_array($optionSet, $setNames, true)) {
                    $optionSet = null;
                }

                $items[] = [
                    'name' => $name,
                    'description' => self::cleanString($item['description'] ?? null, 500),
                    'price_cents' => $price,
                    'price_note' => $priceNote,
                    'option_set' => $optionSet,
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
            'option_sets' => $cleanSets,
            'warnings' => array_values(array_unique(array_filter($warnings))),
        ];
    }

    /**
     * Clamp option sets to the configured caps, coerce every field to its
     * expected type, and repair selection rules so each group's defaults
     * always satisfy its own min/max — the review screen and the builder can
     * then trust the shape blindly.
     *
     * @param  array<int, mixed>  $optionSets
     * @param  array<int, string>  $warnings
     * @return array<int, array{name: string, groups: array<int, array{name: string, min_selections: int, max_selections: ?int, options: array<int, array{name: string, price_delta_cents: int, is_default: bool}>}>}>
     */
    private static function sanitizeOptionSets(array $optionSets, array &$warnings): array
    {
        $maxSets = (int) config('menu_import.max_option_sets');
        $maxGroups = (int) config('menu_import.max_groups_per_set');
        $maxOptions = (int) config('menu_import.max_options_per_group');
        $maxPrice = (int) config('menu_import.max_price_cents');

        $clean = [];
        $usedNames = [];

        foreach (array_slice($optionSets, 0, $maxSets) as $set) {
            if (! is_array($set)) {
                continue;
            }

            $setName = self::cleanString($set['name'] ?? null, 80) ?? 'Options';
            $suffix = 2;
            while (in_array($setName, $usedNames, true)) {
                $setName = mb_substr($setName, 0, 75).' '.$suffix;
                $suffix++;
            }

            $groups = [];
            foreach (array_slice((array) ($set['groups'] ?? []), 0, $maxGroups) as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $groupName = self::cleanString($group['name'] ?? null, 80);
                if ($groupName === null) {
                    continue;
                }

                $options = [];
                foreach (array_slice((array) ($group['options'] ?? []), 0, $maxOptions) as $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $optionName = self::cleanString($option['name'] ?? null, 120);
                    if ($optionName === null) {
                        continue;
                    }

                    $delta = (int) ($option['price_delta_cents'] ?? 0);
                    if (abs($delta) > $maxPrice) {
                        $warnings[] = "An option price for \"{$optionName}\" looked wrong and was reset to \$0 — please check it.";
                        $delta = 0;
                    }

                    $options[] = [
                        'name' => $optionName,
                        'price_delta_cents' => $delta,
                        'is_default' => (bool) ($option['is_default'] ?? false),
                    ];
                }

                if ($options === []) {
                    continue;
                }

                $min = max(0, (int) ($group['min_selections'] ?? 0));
                $min = min($min, count($options));
                $max = $group['max_selections'] ?? null;
                $max = is_numeric($max) ? max(1, (int) $max) : null;
                if ($max !== null && $max < $min) {
                    $max = $min;
                }

                $groups[] = self::repairDefaults([
                    'name' => $groupName,
                    'min_selections' => $min,
                    'max_selections' => $max,
                    'options' => $options,
                ]);
            }

            if ($groups === []) {
                continue;
            }

            $usedNames[] = $setName;
            $clean[] = ['name' => $setName, 'groups' => $groups];
        }

        return $clean;
    }

    /**
     * Ensure the group's defaults land inside [min, max]: trim extra defaults
     * beyond max, then promote the first options until min is met. Imported
     * items inherit these defaults, and item validation rejects defaults that
     * break the group's own rules.
     *
     * @param  array{name: string, min_selections: int, max_selections: ?int, options: array<int, array{name: string, price_delta_cents: int, is_default: bool}>}  $group
     * @return array{name: string, min_selections: int, max_selections: ?int, options: array<int, array{name: string, price_delta_cents: int, is_default: bool}>}
     */
    private static function repairDefaults(array $group): array
    {
        $max = $group['max_selections'];
        $defaults = 0;

        foreach ($group['options'] as $i => $option) {
            if (! $option['is_default']) {
                continue;
            }
            if ($max !== null && $defaults >= $max) {
                $group['options'][$i]['is_default'] = false;

                continue;
            }
            $defaults++;
        }

        foreach ($group['options'] as $i => $option) {
            if ($defaults >= $group['min_selections']) {
                break;
            }
            if (! $group['options'][$i]['is_default']) {
                $group['options'][$i]['is_default'] = true;
                $defaults++;
            }
        }

        return $group;
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
