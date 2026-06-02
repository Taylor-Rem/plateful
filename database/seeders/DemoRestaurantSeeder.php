<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\User;
use App\Support\Menus\MenuBuilder;
use App\Support\Menus\MenuPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
            'hero_tagline' => 'Wood-fired pizza, hand-stretched dough, and red sauce simmered all day. Family recipes since 1998.',
            'hero_cta_label' => 'Start your order',
            'hero_cta_url' => '/menu',
            'about_body' => "Marco moved to Brooklyn from Naples in 1996 with two suitcases and his nonna's tomato sauce recipe. Two years later he opened a six-table shop on Main Street, where he still kneads dough every morning before the sun comes up.\n\nEverything on the menu is made from scratch in-house: the dough rises overnight, the mozzarella is pulled fresh each morning, and the sauce simmers low and slow with San Marzano tomatoes flown in from Italy. We bake every pie in our 900° wood-fired oven — 90 seconds, and it's on your plate.\n\nWe're proud to feed this neighborhood. Thanks for ordering with us.",
            'social_links' => [
                'instagram' => 'https://instagram.com/marcospizzabklyn',
                'facebook' => 'https://facebook.com/marcospizzabklyn',
            ],
        ]);

        $owner = User::create([
            'is_super_admin' => false,
            'name' => 'Marco Rossi',
            'email' => 'owner@marcos.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $owner->restaurants()->attach($restaurant->id, ['role' => 'admin']);

        User::create([
            'is_super_admin' => true,
            'name' => 'Platform Admin',
            'email' => 'admin@plateful.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        app(MenuBuilder::class)->build($restaurant, MenuPresets::TEMPLATED);
    }
}
