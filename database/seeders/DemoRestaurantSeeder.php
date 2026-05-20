<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoRestaurantSeeder extends Seeder
{
    public function run(): void
    {
        $restaurant = Restaurant::create([
            'name' => "Marco's Pizza",
            'subdomain' => 'marcos',
            'custom_domain' => null,
            'description' => 'Authentic wood-fired pizzas since 1998.',
            'primary_color' => '#b91c1c',
            'secondary_color' => '#ffffff',
            'email' => 'hello@marcos.test',
            'phone' => '555-123-4567',
            'street' => '123 Main St',
            'city' => 'Brooklyn',
            'state' => 'NY',
            'postal_code' => '11201',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'is_active' => true,
            'tax_rate_percent' => 8.25,
        ]);

        $owner = User::create([
            'restaurant_id' => null,
            'is_super_admin' => false,
            'name' => 'Marco Rossi',
            'email' => 'owner@marcos.test',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        $owner->restaurants()->attach($restaurant->id);

        User::create([
            'restaurant_id' => null,
            'is_super_admin' => true,
            'name' => 'Platform Admin',
            'email' => 'admin@plateful.test',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        app(CurrentTenant::class)->set($restaurant);

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
                    'position' => $itemPos++,
                ]);
            }
        }

        app(CurrentTenant::class)->clear();
    }
}
