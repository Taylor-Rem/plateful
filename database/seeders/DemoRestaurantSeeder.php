<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemModifier;
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

        $catalog = [
            'Pizzas' => [
                ['Margherita', 1399, 'Tomato, mozzarella, basil.'],
                ['Pepperoni', 1599, 'Tomato, mozzarella, pepperoni.'],
                ['Quattro Formaggi', 1799, 'Four cheeses, no tomato.'],
                ['Diavola', 1699, 'Spicy salami and chili.'],
            ],
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
            foreach ($items as $itemData) {
                [$name, $price, $desc] = $itemData;
                $item = MenuItem::create([
                    'restaurant_id' => $restaurant->id,
                    'menu_category_id' => $category->id,
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => $desc,
                    'price_cents' => $price,
                    'is_available' => true,
                    'position' => $itemPos++,
                ]);

                if ($catName === 'Pizzas' && $name === 'Margherita') {
                    $sizes = [
                        ['Small', -300, false],
                        ['Medium', 0, true],
                        ['Large', 400, false],
                    ];
                    foreach ($sizes as $idx => [$sName, $delta, $isDefault]) {
                        MenuItemModifier::create([
                            'menu_item_id' => $item->id,
                            'name' => $sName,
                            'group_label' => 'Size',
                            'price_delta_cents' => $delta,
                            'is_default' => $isDefault,
                            'position' => $idx,
                        ]);
                    }
                }
            }
        }

        app(CurrentTenant::class)->clear();
    }
}
