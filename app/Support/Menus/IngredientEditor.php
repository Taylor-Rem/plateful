<?php

namespace App\Support\Menus;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use Illuminate\Support\Facades\DB;

/**
 * Owner-facing writes to an item's ingredient rows. Every write ends with a
 * recompile so the configurator, cart, and tickets see the change at once.
 */
class IngredientEditor
{
    public function __construct(private IngredientGroupCompiler $compiler) {}

    /**
     * Replace the item's ingredient list with the given rows, in order.
     * Rows carrying an id that belongs to the item are updated (so their
     * generated option ids survive); the rest are created; rows not
     * mentioned are deleted.
     *
     * @param  array<int, array{id?: int|null, name: string, is_removable?: bool, extra_price_cents?: int|null, swap_template_id?: int|null}>  $rows
     */
    public function sync(MenuItem $item, array $rows): void
    {
        DB::transaction(function () use ($item, $rows): void {
            $existing = $item->ingredients()->get()->keyBy('id');
            $keep = [];

            foreach (array_values($rows) as $position => $row) {
                $attrs = [
                    'menu_item_id' => $item->id,
                    'name' => trim((string) $row['name']),
                    'position' => $position,
                    'is_removable' => (bool) ($row['is_removable'] ?? true),
                    'extra_price_cents' => isset($row['extra_price_cents']) ? (int) $row['extra_price_cents'] : null,
                    'swap_template_id' => isset($row['swap_template_id']) ? (int) $row['swap_template_id'] : null,
                ];

                $id = isset($row['id']) ? (int) $row['id'] : null;
                $ingredient = $id !== null ? $existing->get($id) : null;

                if ($ingredient instanceof MenuItemIngredient) {
                    $ingredient->update($attrs);
                } else {
                    $ingredient = MenuItemIngredient::create($attrs);
                }

                $keep[] = $ingredient->id;
            }

            $item->ingredients()->whereNotIn('id', $keep ?: [0])->delete();

            $this->compiler->compile($item->fresh());
        });
    }

    /**
     * Apply rules by ingredient *name* to every item in the category that
     * has that ingredient ("mortadella: removable, extra $1.50" everywhere
     * it appears). Items without the ingredient are untouched. Returns the
     * number of items changed.
     *
     * @param  array<int, array{name: string, is_removable?: bool, extra_price_cents?: int|null, swap_template_id?: int|null}>  $rules
     */
    public function applyRules(MenuCategory $category, array $rules): int
    {
        $byName = [];
        foreach ($rules as $rule) {
            $byName[mb_strtolower(trim((string) $rule['name']))] = $rule;
        }

        if ($byName === []) {
            return 0;
        }

        $touched = 0;

        DB::transaction(function () use ($category, $byName, &$touched): void {
            $items = MenuItem::query()
                ->where('menu_category_id', $category->id)
                ->with('ingredients')
                ->get();

            foreach ($items as $item) {
                $changed = false;

                foreach ($item->ingredients as $ingredient) {
                    $rule = $byName[mb_strtolower(trim($ingredient->name))] ?? null;
                    if ($rule === null) {
                        continue;
                    }

                    $ingredient->fill([
                        'is_removable' => (bool) ($rule['is_removable'] ?? $ingredient->is_removable),
                        'extra_price_cents' => array_key_exists('extra_price_cents', $rule) ? $rule['extra_price_cents'] : $ingredient->extra_price_cents,
                        'swap_template_id' => array_key_exists('swap_template_id', $rule) ? $rule['swap_template_id'] : $ingredient->swap_template_id,
                    ]);

                    if ($ingredient->isDirty()) {
                        $ingredient->save();
                        $changed = true;
                    }
                }

                if ($changed) {
                    $this->compiler->compile($item->fresh());
                    $touched++;
                }
            }
        });

        return $touched;
    }
}
