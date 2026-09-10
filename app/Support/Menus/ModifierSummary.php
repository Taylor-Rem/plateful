<?php

namespace App\Support\Menus;

use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;

/**
 * Renders a cart/order line's modifier snapshot as the parts a human needs:
 * what deviates from the item as printed. An "ingredient" group is one
 * ingredient's level row — Regular is silent, the others read "No X",
 * "Half X", "Double X". Every other kind (size, swaps, choices) lists its
 * selections, plus "No X" for a default the customer turned off — except in
 * a pick-one group where the pick already says it ("Large", never
 * "No Medium · Large"). Legacy "included" groups hide their default
 * selections and show removals as "No X".
 *
 * Snapshots written before 2026-09-10 have no `kind`, `is_default`, or
 * `removed` keys; they render exactly as before (every selection).
 */
final class ModifierSummary
{
    /**
     * @param  array<string, mixed>|null  $modifiers
     * @return array<int, array{groupName: string, selectionNames: array<int, string>}>
     */
    public static function groups(?array $modifiers): array
    {
        $out = [];

        foreach (self::rawGroups($modifiers) as $group) {
            $names = self::deviationNames($group);

            if ($names === []) {
                continue;
            }

            $out[] = [
                'groupName' => (string) ($group['group_name'] ?? ''),
                'selectionNames' => $names,
            ];
        }

        return $out;
    }

    /**
     * Flat list of parts, e.g. ["12\"", "No mortadella", "Extra provolone"].
     *
     * @param  array<string, mixed>|null  $modifiers
     * @return array<int, string>
     */
    public static function parts(?array $modifiers): array
    {
        $parts = [];

        foreach (self::rawGroups($modifiers) as $group) {
            foreach (self::deviationNames($group) as $name) {
                $parts[] = $name;
            }
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>|null  $modifiers
     */
    public static function summary(?array $modifiers, string $glue = ' · '): string
    {
        return implode($glue, self::parts($modifiers));
    }

    /**
     * Every option id the snapshot selected (removals are not selections).
     *
     * @param  array<string, mixed>|null  $modifiers
     * @return array<int, int>
     */
    public static function selectedOptionIds(?array $modifiers): array
    {
        $ids = [];

        foreach (self::rawGroups($modifiers) as $group) {
            foreach ($group['selections'] ?? [] as $selection) {
                if (is_array($selection) && isset($selection['option_id'])) {
                    $ids[] = (int) $selection['option_id'];
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>|null  $modifiers
     * @return array<int, array<string, mixed>>
     */
    private static function rawGroups(?array $modifiers): array
    {
        if (! is_array($modifiers) || ! isset($modifiers['groups']) || ! is_array($modifiers['groups'])) {
            return [];
        }

        return array_values(array_filter($modifiers['groups'], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<int, string>
     */
    /**
     * "Mortadella: None" → "No Mortadella"; Half / Double prefix the
     * ingredient; Regular (the default) says nothing.
     *
     * @param  array<string, mixed>  $group
     * @return array<int, string>
     */
    private static function levelDeviation(array $group): array
    {
        $ingredient = (string) ($group['group_name'] ?? '');
        $selection = collect($group['selections'] ?? [])->first(fn ($s) => is_array($s) && isset($s['option_name']));

        if ($selection === null || $ingredient === '') {
            return [];
        }

        return match ((string) $selection['option_name']) {
            ItemTemplateOption::LEVEL_NONE => ['No '.$ingredient],
            ItemTemplateOption::LEVEL_HALF => ['Half '.$ingredient],
            ItemTemplateOption::LEVEL_DOUBLE => ['Double '.$ingredient],
            default => [],
        };
    }

    private static function deviationNames(array $group): array
    {
        $kind = (string) ($group['kind'] ?? ItemTemplateGroup::KIND_CHOICE);
        $names = [];

        if ($kind === ItemTemplateGroup::KIND_INGREDIENT) {
            return self::levelDeviation($group);
        }

        foreach ($group['selections'] ?? [] as $selection) {
            if (! is_array($selection) || ! isset($selection['option_name'])) {
                continue;
            }

            if ($kind === ItemTemplateGroup::KIND_INCLUDED && ($selection['is_default'] ?? false)) {
                continue;
            }

            $names[] = (string) $selection['option_name'];
        }

        $pickOneAlreadyPicked = ($group['single_select'] ?? false) && $names !== [];

        if (! $pickOneAlreadyPicked) {
            foreach ($group['removed'] ?? [] as $removed) {
                if (is_array($removed) && isset($removed['option_name'])) {
                    $names[] = 'No '.$removed['option_name'];
                }
            }
        }

        return $names;
    }
}
