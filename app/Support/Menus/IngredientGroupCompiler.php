<?php

namespace App\Support\Menus;

use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns an item's ingredient rows into the option groups the configurator,
 * cart, pricing, and order integrity check run on. Deterministic and
 * idempotent: run it after every ingredient save.
 *
 *  - Every ingredient with at least one rule → one item-owned "ingredient"
 *    group named after it: a pick-one of its levels (None if removable,
 *    Half if allowed, Regular always, Double if priced), Regular default,
 *    only Double carrying a price. An ingredient with no rules gets no row.
 *  - Ingredients with a swap set → the set's template is attached to the
 *    item through the ingredient and the option matching the ingredient
 *    becomes the item's default in that group (created at $0 if missing).
 *
 * Groups and options are upserted by ingredient (and level) so their ids
 * survive re-saves — an existing cart line keeps validating when the owner
 * tweaks a price. Legacy "included" / "extras" groups from before levels
 * are removed on the first recompile.
 */
class IngredientGroupCompiler
{
    public function compile(MenuItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $ingredients = $item->ingredients()->get();

            $newDefaults = collect();
            $keepGroupIds = [];

            foreach ($ingredients as $ingredient) {
                $group = $this->syncLevelGroup($item, $ingredient);
                if ($group === null) {
                    continue;
                }

                $keepGroupIds[] = $group->id;
                $regular = $group->options->firstWhere('name', ItemTemplateOption::LEVEL_REGULAR);
                if ($regular) {
                    $newDefaults->push($regular->id);
                }
            }

            // Anything item-owned we did not just produce is stale: a level
            // row for a deleted ingredient, or the pre-levels groups.
            ItemTemplateGroup::query()
                ->where('menu_item_id', $item->id)
                ->whereNotIn('id', $keepGroupIds ?: [0])
                ->delete();

            $swapDefaults = $this->syncSwapSets(
                $item,
                $ingredients->filter(fn (MenuItemIngredient $i) => $i->isSwappable()),
            );
            $newDefaults = $newDefaults->merge($swapDefaults->values());

            $this->syncDefaults($item, $newDefaults, $swapDefaults->keys());

            $item->unsetRelation('ownGroups');
            $item->unsetRelation('templates');
            $item->unsetRelation('defaultSelections');
        });
    }

    /**
     * Upsert the pick-one level row for one ingredient; null when the
     * ingredient has no rules (only Regular) and therefore no row.
     */
    private function syncLevelGroup(MenuItem $item, MenuItemIngredient $ingredient): ?ItemTemplateGroup
    {
        $levels = $ingredient->levels();

        $group = ItemTemplateGroup::query()
            ->where('menu_item_id', $item->id)
            ->where('menu_item_ingredient_id', $ingredient->id)
            ->where('kind', ItemTemplateGroup::KIND_INGREDIENT)
            ->first();

        if ($levels === [ItemTemplateOption::LEVEL_REGULAR]) {
            $group?->delete();

            return null;
        }

        $attrs = [
            'item_template_id' => null,
            'menu_item_id' => $item->id,
            'menu_item_ingredient_id' => $ingredient->id,
            'name' => $ingredient->name,
            'kind' => ItemTemplateGroup::KIND_INGREDIENT,
            'min_selections' => 1,
            'max_selections' => 1,
            'position' => (int) $ingredient->position,
        ];

        if ($group) {
            $group->update($attrs);
        } else {
            $group = ItemTemplateGroup::create($attrs);
        }

        $keep = [];
        foreach ($levels as $index => $level) {
            $option = ItemTemplateOption::query()
                ->where('item_template_group_id', $group->id)
                ->where('kind', ItemTemplateOption::KIND_LEVEL)
                ->where('name', $level)
                ->first();

            $optionAttrs = [
                'item_template_group_id' => $group->id,
                'menu_item_ingredient_id' => $ingredient->id,
                'kind' => ItemTemplateOption::KIND_LEVEL,
                'name' => $level,
                'price_delta_cents' => $level === ItemTemplateOption::LEVEL_DOUBLE ? (int) $ingredient->extra_price_cents : 0,
                'is_available' => true,
                'position' => $index,
            ];

            if ($option) {
                $option->update($optionAttrs);
            } else {
                $option = ItemTemplateOption::create($optionAttrs);
            }

            $keep[] = $option->id;
        }

        ItemTemplateOption::query()
            ->where('item_template_group_id', $group->id)
            ->whereNotIn('id', $keep)
            ->delete();

        $group->setRelation('options', ItemTemplateOption::query()
            ->where('item_template_group_id', $group->id)
            ->orderBy('position')
            ->get());

        return $group;
    }

    /**
     * Attach each swap set through its ingredient, make sure the ingredient
     * has an option in the set, and return group id → default option id.
     * Detaches sets whose ingredient no longer swaps.
     *
     * @param  Collection<int, MenuItemIngredient>  $swapIngredients
     * @return Collection<int, int>
     */
    private function syncSwapSets(MenuItem $item, Collection $swapIngredients): Collection
    {
        $defaults = collect();
        $keepIngredientIds = [];

        foreach ($swapIngredients as $ingredient) {
            $template = ItemTemplate::query()
                ->with('groups.options')
                ->find($ingredient->swap_template_id);

            if (! $template || $template->restaurant_id !== $item->restaurant_id) {
                throw new InvalidArgumentException("Swap set for \"{$ingredient->name}\" does not belong to this restaurant.");
            }

            if (! $template->isSwapSet()) {
                throw new InvalidArgumentException("\"{$template->name}\" is not a swap set (it needs exactly one pick-one group).");
            }

            $group = $template->groups->first();
            if ($group->kind !== ItemTemplateGroup::KIND_SWAP) {
                $group->update(['kind' => ItemTemplateGroup::KIND_SWAP]);
            }

            $option = $group->options->first(
                fn (ItemTemplateOption $o) => mb_strtolower(trim($o->name)) === mb_strtolower(trim($ingredient->name)),
            );

            if (! $option) {
                $option = ItemTemplateOption::create([
                    'item_template_group_id' => $group->id,
                    'kind' => ItemTemplateOption::KIND_CHOICE,
                    'name' => $ingredient->name,
                    'price_delta_cents' => 0,
                    'is_available' => true,
                    'position' => (int) ($group->options->max('position') ?? -1) + 1,
                ]);
            }

            $pivot = DB::table('menu_item_templates')
                ->where('menu_item_id', $item->id)
                ->where('item_template_id', $template->id)
                ->first();

            if ($pivot) {
                if ($pivot->menu_item_ingredient_id !== $ingredient->id) {
                    DB::table('menu_item_templates')
                        ->where('id', $pivot->id)
                        ->update(['menu_item_ingredient_id' => $ingredient->id, 'updated_at' => now()]);
                }
            } else {
                $position = (int) (DB::table('menu_item_templates')
                    ->where('menu_item_id', $item->id)
                    ->max('position') ?? -1) + 1;

                $item->templates()->attach($template->id, [
                    'position' => $position,
                    'menu_item_ingredient_id' => $ingredient->id,
                ]);
            }

            $keepIngredientIds[] = $ingredient->id;
            $defaults->put($group->id, $option->id);
        }

        DB::table('menu_item_templates')
            ->where('menu_item_id', $item->id)
            ->whereNotNull('menu_item_ingredient_id')
            ->whereNotIn('menu_item_ingredient_id', $keepIngredientIds ?: [0])
            ->delete();

        return $defaults;
    }

    /**
     * Item defaults = what the owner set on hand-attached templates, plus
     * Regular on every level row, plus one default per swap group. Defaults
     * pointing at options that no longer belong to the item are dropped.
     *
     * @param  Collection<int, int>  $generatedDefaults
     * @param  Collection<int, int>  $swapGroupIds
     */
    private function syncDefaults(MenuItem $item, Collection $generatedDefaults, Collection $swapGroupIds): void
    {
        $item->unsetRelation('templates');
        $item->unsetRelation('ownGroups');

        $validOptionIds = collect();
        $optionToGroup = [];
        $levelGroupIds = [];
        foreach ($item->optionGroups() as $group) {
            if ($group->kind === ItemTemplateGroup::KIND_INGREDIENT) {
                $levelGroupIds[] = $group->id;
            }
            foreach ($group->options as $option) {
                $validOptionIds->push($option->id);
                $optionToGroup[$option->id] = $group->id;
            }
        }

        $existing = $item->defaultSelections()->pluck('item_template_options.id')->map(fn ($id) => (int) $id);

        $kept = $existing
            ->filter(fn (int $id) => $validOptionIds->contains($id))
            // Compiled groups get exactly the compiled default.
            ->reject(fn (int $id) => $swapGroupIds->contains($optionToGroup[$id] ?? 0))
            ->reject(fn (int $id) => in_array($optionToGroup[$id] ?? 0, $levelGroupIds, true));

        $final = $kept->merge($generatedDefaults)->map(fn ($id) => (int) $id)->unique()->values()->all();

        $item->defaultSelections()->sync($final);
    }
}
