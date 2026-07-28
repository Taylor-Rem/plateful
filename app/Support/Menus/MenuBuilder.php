<?php

namespace App\Support\Menus;

use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Str;

/**
 * Builds a restaurant's menu from a cuisine preset. Single source of truth for
 * both the demo seeder (italian / pizza template) and the make:restaurant
 * developer command (all cuisines).
 */
class MenuBuilder
{
    public function __construct(private CurrentTenant $tenant) {}

    /**
     * Create the menu for a cuisine. Runs with the restaurant set as the
     * current tenant so tenant-scoped relations (default selections) resolve,
     * restoring any previously-set tenant afterwards.
     */
    public function build(Restaurant $restaurant, string $cuisine): void
    {
        $this->withTenant($restaurant, function () use ($restaurant, $cuisine): void {
            if ($cuisine === MenuPresets::TEMPLATED) {
                $this->buildItalian($restaurant);
            } else {
                $this->buildFlat($restaurant, MenuPresets::flat($cuisine));
            }
        });
    }

    /**
     * Create the menu from an owner-confirmed AI import draft. Slugs are
     * uniquified because extracted menus (unlike curated presets) can repeat
     * names across categories. Option sets become reusable item templates;
     * items reference them by name. Returns the number of items created.
     *
     * @param  array<int, array{name: string, items: array<int, array{name: string, description?: ?string, price_cents: int, option_set?: ?string}>}>  $categories
     * @param  array<int, array{name: string, groups: array<int, array{name: string, min_selections: int, max_selections: ?int, options: array<int, array{name: string, price_delta_cents: int, is_default: bool}>}>}>  $optionSets
     */
    public function buildFromImport(Restaurant $restaurant, array $categories, array $optionSets = []): int
    {
        return $this->withTenant($restaurant, function () use ($restaurant, $categories, $optionSets): int {
            $templates = $this->createImportedTemplates($restaurant, $optionSets);

            $usedCategorySlugs = [];
            $usedItemSlugs = [];
            $created = 0;

            foreach ($categories as $catPos => $category) {
                $menuCategory = MenuCategory::create([
                    'restaurant_id' => $restaurant->id,
                    'name' => $category['name'],
                    'slug' => $this->uniqueSlug($category['name'], $usedCategorySlugs),
                    'position' => $catPos,
                    'is_active' => true,
                ]);

                foreach ($category['items'] as $itemPos => $item) {
                    $template = $templates[$item['option_set'] ?? ''] ?? null;

                    $menuItem = MenuItem::create([
                        'restaurant_id' => $restaurant->id,
                        'menu_category_id' => $menuCategory->id,
                        'item_template_id' => $template['id'] ?? null,
                        'name' => $item['name'],
                        'slug' => $this->uniqueSlug($item['name'], $usedItemSlugs),
                        'description' => $item['description'] ?? null,
                        'price_cents' => $item['price_cents'],
                        'is_available' => true,
                        'is_featured' => false,
                        'position' => $itemPos,
                    ]);

                    if ($template !== null && $template['default_option_ids'] !== []) {
                        $menuItem->defaultSelections()->sync($template['default_option_ids']);
                    }

                    $created++;
                }
            }

            return $created;
        });
    }

    /**
     * Create an item template per imported option set and return them keyed
     * by set name, each with the option ids items should inherit as defaults.
     *
     * @param  array<int, array{name: string, groups: array<int, array{name: string, min_selections: int, max_selections: ?int, options: array<int, array{name: string, price_delta_cents: int, is_default: bool}>}>}>  $optionSets
     * @return array<string, array{id: int, default_option_ids: array<int, int>}>
     */
    private function createImportedTemplates(Restaurant $restaurant, array $optionSets): array
    {
        $templates = [];

        foreach ($optionSets as $setPos => $set) {
            $template = ItemTemplate::create([
                'restaurant_id' => $restaurant->id,
                'name' => $set['name'],
                'description' => null,
                'is_active' => true,
                'position' => $setPos,
            ]);

            $defaultOptionIds = [];

            foreach ($set['groups'] as $groupPos => $group) {
                $templateGroup = ItemTemplateGroup::create([
                    'item_template_id' => $template->id,
                    'name' => $group['name'],
                    'min_selections' => $group['min_selections'],
                    'max_selections' => $group['max_selections'],
                    'position' => $groupPos,
                ]);

                foreach ($group['options'] as $optionPos => $option) {
                    $templateOption = ItemTemplateOption::create([
                        'item_template_group_id' => $templateGroup->id,
                        'name' => $option['name'],
                        'price_delta_cents' => $option['price_delta_cents'],
                        'is_available' => true,
                        'position' => $optionPos,
                    ]);

                    if ($option['is_default']) {
                        $defaultOptionIds[] = $templateOption->id;
                    }
                }
            }

            $templates[$set['name']] = [
                'id' => $template->id,
                'default_option_ids' => $defaultOptionIds,
            ];
        }

        return $templates;
    }

    /**
     * Run $callback with the restaurant set as the current tenant, restoring
     * any previously-set tenant afterwards.
     */
    private function withTenant(Restaurant $restaurant, \Closure $callback): mixed
    {
        $restored = $this->tenant->get();
        $this->tenant->set($restaurant);

        try {
            return $callback();
        } finally {
            if ($restored !== null) {
                $this->tenant->set($restored);
            } else {
                $this->tenant->clear();
            }
        }
    }

    /**
     * @param  array<string, true>  $used
     */
    private function uniqueSlug(string $name, array &$used): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while (isset($used[$slug])) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        $used[$slug] = true;

        return $slug;
    }

    /**
     * Build a flat catalog: categories of simple priced items with no
     * customization template.
     *
     * @param  array<string, array<int, array{0: string, 1: int, 2: ?string, 3: bool}>>  $catalog
     */
    private function buildFlat(Restaurant $restaurant, array $catalog): void
    {
        $catPos = 0;

        foreach ($catalog as $catName => $items) {
            $category = MenuCategory::create([
                'restaurant_id' => $restaurant->id,
                'name' => $catName,
                'slug' => Str::slug($catName),
                'position' => $catPos++,
                'is_active' => true,
            ]);

            $itemPos = 0;
            foreach ($items as [$name, $price, $desc, $featured]) {
                MenuItem::create([
                    'restaurant_id' => $restaurant->id,
                    'menu_category_id' => $category->id,
                    'item_template_id' => null,
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => $desc,
                    'price_cents' => $price,
                    'is_available' => true,
                    'is_featured' => $featured,
                    'position' => $itemPos++,
                ]);
            }
        }
    }

    /**
     * Build the configurable-pizza menu: a Pizza ItemTemplate with size, crust,
     * cheese, meat and vegetable groups, pizza items with default selections,
     * and simple Sides/Drinks/Desserts categories.
     */
    private function buildItalian(Restaurant $restaurant): void
    {
        // -----------------------------------------------------------------
        // Pizza template (groups and options).
        // Deltas are in cents.
        // -----------------------------------------------------------------
        $pizzaTemplate = ItemTemplate::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Pizza',
            'description' => 'Configurable pizza with size, crust, cheeses, meats and vegetables.',
            'is_active' => true,
            'position' => 0,
        ]);

        $groupsDef = [
            ['Size', 1, 1, [
                ['Small', -200],
                ['Medium', 0],
                ['Large', 300],
            ]],
            ['Crust', 1, 1, [
                ['Hand Tossed', 0],
                ['Thin', 0],
                ['Stuffed', 200],
            ]],
            ['Cheeses', 0, 3, [
                ['Mozzarella', 0],
                ['Cheddar', 100],
                ['Parmesan', 100],
                ['Feta', 150],
            ]],
            ['Meats', 0, null, [
                ['Pepperoni', 200],
                ['Sausage', 200],
                ['Bacon', 300],
                ['Chicken', 300],
                ['Pulled Pork', 300],
            ]],
            ['Vegetables', 0, null, [
                ['Mushrooms', 50],
                ['Onions', 50],
                ['Bell Peppers', 50],
                ['Olives', 50],
                ['Pineapple', 100],
                ['Spinach', 50],
                ['Tomato', 50],
            ]],
        ];

        $optionsByGroupAndName = []; // [groupName][optionName] => ItemTemplateOption

        foreach ($groupsDef as $gIdx => [$gName, $min, $max, $opts]) {
            $group = ItemTemplateGroup::create([
                'item_template_id' => $pizzaTemplate->id,
                'name' => $gName,
                'min_selections' => $min,
                'max_selections' => $max,
                'position' => $gIdx,
            ]);

            foreach ($opts as $oIdx => [$oName, $delta]) {
                $opt = ItemTemplateOption::create([
                    'item_template_group_id' => $group->id,
                    'name' => $oName,
                    'price_delta_cents' => $delta,
                    'is_available' => true,
                    'position' => $oIdx,
                ]);
                $optionsByGroupAndName[$gName][$oName] = $opt;
            }
        }

        $deltaFor = function (string $group, string $option) use ($optionsByGroupAndName): int {
            return (int) $optionsByGroupAndName[$group][$option]->price_delta_cents;
        };

        // -----------------------------------------------------------------
        // Menu categories + items.
        //
        // Price-cents math note: each pizza's price_cents below = the
        // intended "base displayed price" plus the sum of price_delta_cents
        // for its default selections. The configurator shows exactly that
        // price when no changes are made.
        // -----------------------------------------------------------------

        $pizzaItems = [
            // [name, base_displayed_cents, description, [['Group','Option'], ...]]
            ['Margherita Pizza', 1200, 'Tomato, mozzarella, basil.', [
                ['Size', 'Medium'], ['Crust', 'Hand Tossed'],
                ['Cheeses', 'Mozzarella'], ['Vegetables', 'Tomato'],
            ]],
            ['Pepperoni Pizza', 1400, 'Tomato, mozzarella, pepperoni.', [
                ['Size', 'Medium'], ['Crust', 'Hand Tossed'],
                ['Cheeses', 'Mozzarella'], ['Meats', 'Pepperoni'],
            ]],
            ['Bacon Pizza', 1500, 'Tomato, mozzarella, bacon.', [
                ['Size', 'Medium'], ['Crust', 'Hand Tossed'],
                ['Cheeses', 'Mozzarella'], ['Meats', 'Bacon'],
            ]],
            ['Meat Lovers Pizza', 1800, 'Pepperoni, sausage, bacon.', [
                ['Size', 'Medium'], ['Crust', 'Hand Tossed'],
                ['Cheeses', 'Mozzarella'],
                ['Meats', 'Pepperoni'], ['Meats', 'Sausage'], ['Meats', 'Bacon'],
            ]],
            ['Build Your Own Pizza', 1000, 'Pick your size, crust, and toppings.', [
                ['Size', 'Medium'], ['Crust', 'Hand Tossed'],
            ]],
        ];

        $simpleCatalog = [
            'Sides' => [
                ['Garlic Knots', 599, 'Six knots with marinara.'],
                ['Caesar Salad', 899, 'Romaine, parmesan, croutons.'],
                ['Bruschetta', 799, 'Tomato, basil, olive oil.'],
            ],
            'Drinks' => [
                ['Soda', 299, null],
                ['Sparkling Water', 399, null],
                ['Italian Lemonade', 499, null],
            ],
            'Desserts' => [
                ['Tiramisu', 799, null],
                ['Cannoli', 599, null],
                ['Gelato', 699, null],
            ],
        ];

        // Pizzas category first.
        $pizzasCat = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Pizzas',
            'slug' => 'pizzas',
            'position' => 0,
            'is_active' => true,
        ]);

        foreach ($pizzaItems as $itemIdx => [$name, $baseDisplay, $desc, $defaults]) {
            $deltaSum = 0;
            foreach ($defaults as [$gName, $oName]) {
                $deltaSum += $deltaFor($gName, $oName);
            }

            $item = MenuItem::create([
                'restaurant_id' => $restaurant->id,
                'menu_category_id' => $pizzasCat->id,
                'item_template_id' => $pizzaTemplate->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $desc,
                'price_cents' => $baseDisplay + $deltaSum,
                'is_available' => true,
                'is_featured' => in_array($name, ['Margherita Pizza', 'Pepperoni Pizza', 'Meat Lovers Pizza'], true),
                'position' => $itemIdx,
            ]);

            $optionIds = [];
            foreach ($defaults as [$gName, $oName]) {
                $optionIds[] = $optionsByGroupAndName[$gName][$oName]->id;
            }

            $item->defaultSelections()->sync($optionIds);
        }

        $catPos = 1;
        foreach ($simpleCatalog as $catName => $items) {
            $category = MenuCategory::create([
                'restaurant_id' => $restaurant->id,
                'name' => $catName,
                'slug' => Str::slug($catName),
                'position' => $catPos++,
                'is_active' => true,
            ]);

            $itemPos = 0;
            foreach ($items as [$name, $price, $desc]) {
                MenuItem::create([
                    'restaurant_id' => $restaurant->id,
                    'menu_category_id' => $category->id,
                    'item_template_id' => null,
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => $desc,
                    'price_cents' => $price,
                    'is_available' => true,
                    'is_featured' => $name === 'Garlic Knots',
                    'position' => $itemPos++,
                ]);
            }
        }
    }
}
