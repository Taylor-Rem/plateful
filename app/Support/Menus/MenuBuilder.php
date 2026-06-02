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
        $restored = $this->tenant->get();
        $this->tenant->set($restaurant);

        try {
            if ($cuisine === MenuPresets::TEMPLATED) {
                $this->buildItalian($restaurant);
            } else {
                $this->buildFlat($restaurant, MenuPresets::flat($cuisine));
            }
        } finally {
            if ($restored !== null) {
                $this->tenant->set($restored);
            } else {
                $this->tenant->clear();
            }
        }
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
