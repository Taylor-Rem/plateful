<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

if (! function_exists('adminOrderRestaurant')) {
    function adminOrderRestaurant(string $sub = 'marcos'): Restaurant
    {
        return Restaurant::create([
            'name' => "R-{$sub}",
            'subdomain' => $sub,
            'email' => "hello@{$sub}.test",
            'street' => '1 Main',
            'city' => 'NYC',
            'state' => 'NY',
            'postal_code' => '10001',
        ]);
    }
}

if (! function_exists('adminForRestaurant')) {
    function adminForRestaurant(Restaurant $r, string $email = 'owner@m.test'): User
    {
        $u = User::create([
            'restaurant_id' => null,
            'is_super_admin' => false,
            'name' => 'Owner',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);
        $u->restaurants()->attach($r->id);

        return $u;
    }
}

if (! function_exists('makeOrder')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function makeOrder(Restaurant $r, array $overrides = []): Order
    {
        $defaults = [
            'restaurant_id' => $r->id,
            'user_id' => null,
            'customer_name' => 'Alice Customer',
            'customer_email' => 'alice@example.test',
            'customer_phone' => null,
            'number' => Str::upper(Str::random(8)),
            'status' => OrderStatus::Pending,
            'type' => OrderType::Pickup,
            'subtotal_cents' => 1000,
            'tax_cents' => 100,
            'tip_cents' => 0,
            'delivery_fee_cents' => 0,
            'application_fee_cents' => 0,
            'total_cents' => 1100,
            'placed_at' => now(),
            'confirmation_token' => Str::random(64),
        ];

        $order = Order::create(array_merge($defaults, $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'name' => 'Sample item',
            'unit_price_cents' => 1000,
            'quantity' => 1,
            'subtotal_cents' => 1000,
            'modifiers' => null,
        ]);

        return $order;
    }
}
