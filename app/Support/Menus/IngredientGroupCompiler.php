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
 *  - Removable, non-swappable ingredients → one item-owned "included" group
 *    (min 0, no max), one $0 option per ingredient, all set as item
 *    defaults. Unchecking one means "leave it out".
 *  - Ingredients with an extra price → one item-owned "extras" group with
 *    "Extra {name}" options carrying that price.
 *  - Ingredients with a swap set → the set's template is attached to the
 *    item through the ingredient and the option matching the ingredient
 *    becomes the item's default in that group (created at $0 if missing).
 *
 * Generated options are upserted by (ingredient, kind) so their ids survive
 * re-saves — an existing cart line keeps validating when the owner tweaks a
 * price.
 */
class IngredientGroupCompiler
{
    public const INCLUDED_GROUP_NAME = 'Included';

    public const EXTRAS_GROUP_NAME = 'Extras';

    public function compile(MenuItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $ingredients = $item->ingredients()->get();

            $includedIngredients = $ingredients->filter(
                fn (MenuItemIngredient $i) => $i->is_removable && ! $i->isSwappable(),
            );
            $extraIngredients = $ingredients->filter(
                fn (MenuItemIngredient $i) => $i->offersExtra(),
            );
            $swapIngredients = $ingredients->filter(
                fn (MenuItemIngredient $i) => $i->isSwappable(),
            );

            $newDefaults = collect();

            $included = $this->syncOwnGroup(
                $item,
                ItemTemplateGroup::KIND_INCLUDED,
                self::INCLUDED_GROUP_NAME,
                0,
                $includedIngredients->map(fn (MenuItemIngredient $i) => [
                    'ingredient' => $i,
                    'kind' => ItemTemplateOption::KIND_INCLUDED,
                    'name' => $i->name,
                    'price_delta_cents' => 0,
                ]),
            );
            if ($included) {
                $newDefaults = $newDefaults->merge($included->options->pluck('id'));
            }

            $this->syncOwnGroup(
                $item,
                ItemTemplateGroup::KIND_EXTRAS,
                self::EXTRAS_GROUP_NAME,
                1,
                $extraIngredients->map(fn (MenuItemIngredient $i) => [
                    'ingredient' => $i,
                    'kind' => ItemTemplateOption::KIND_EXTRA,
                    'name' => 'Extra '.$i->name,
                    'price_delta_cents' => (int) $i->extra_price_cents,
                ]),
            );

            $swapDefaults = $this->syncSwapSets($item, $swapIngredients);
            $newDefaults = $newDefaults->merge($swapDefaults->values());

            $this->syncDefaults($item, $newDefaults, $swapDefaults->keys());

            $item->unsetRelation('ownGroups');
            $item->unsetRelation('templates');
            $item->unsetRelation('defaultSelections');
        });
    }

    /**
     * Upsert one item-owned group and its generated options; delete the
     * group when it would be empty.
     *
     * @param  Collection<int, array{ingredient: MenuItemIngredient, kind: string, name: string, price_delta_cents: int}>  $specs
     */
    private function syncOwnGroup(MenuItem $item, string $kind, string $name, int $position, Collection $specs): ?ItemTemplateGroup
    {
        $group = ItemTemplateGroup::query()
            ->where('menu_item_id', $item->id)
            ->where('kind', $kind)
            ->first();

        if ($specs->isEmpty()) {
            $group?->delete();

            return null;
        }

        $attrs = [
            'item_template_id' => null,
            'menu_item_id' => $item->id,
            'name' => $name,
            'kind' => $kind,
            'min_selections' => 0,
            'max_selections' => null,
            'position' => $position,
        ];

        if ($group) {
            $group->update($attrs);
        } else {
            $group = ItemTemplateGroup::create($attrs);
        }

        $keep = [];
        foreach ($specs->values() as $index => $spec) {
            $option = ItemTemplateOption::query()
                ->where('item_template_group_id', $group->id)
                ->where('menu_item_ingredient_id', $spec['ingredient']->id)
                ->where('kind', $spec['kind'])
                ->first();

            $optionAttrs = [
                'item_template_group_id' => $group->id,
                'menu_item_ingredient_id' => $spec['ingredient']->id,
                'kind' => $spec['kind'],
                'name' => $spec['name'],
                'price_delta_cents' => $spec['price_delta_cents'],
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
     * every generated "included" option, plus one default per swap group.
     * Defaults pointing at options that no longer belong to the item are
     * dropped.
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
        foreach ($item->optionGroups() as $group) {
            foreach ($group->options as $option) {
                $validOptionIds->push($option->id);
                $optionToGroup[$option->id] = $group->id;
            }
        }

        $existing = $item->defaultSelections()->pluck('item_template_options.id')->map(fn ($id) => (int) $id);

        $kept = $existing
            ->filter(fn (int $id) => $validOptionIds->contains($id))
            // Swap groups get exactly the compiled default; drop any other
            // default the owner had in those groups.
            ->reject(fn (int $id) => $swapGroupIds->contains($optionToGroup[$id] ?? 0));

        $final = $kept->merge($generatedDefaults)->map(fn ($id) => (int) $id)->unique()->values()->all();

        $item->defaultSelections()->sync($final);
    }
}
