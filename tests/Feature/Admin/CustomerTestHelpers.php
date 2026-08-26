<?php

use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

if (! function_exists('customerUser')) {
    function customerUser(string $name, string $email): User
    {
        return User::create([
            'is_super_admin' => false,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }
}

if (! function_exists('customerPivot')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function customerPivot(Restaurant $r, User $u, array $overrides = []): RestaurantCustomer
    {
        return RestaurantCustomer::create(array_merge([
            'user_id' => $u->id,
            'restaurant_id' => $r->id,
            'first_ordered_at' => now()->subDays(10),
            'last_ordered_at' => now()->subDays(2),
            'total_orders' => 3,
            'total_spent_cents' => 4500,
        ], $overrides));
    }
}
