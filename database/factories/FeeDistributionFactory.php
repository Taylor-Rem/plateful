<?php

namespace Database\Factories;

use App\Enums\RevenueRole;
use App\Models\FeeDistribution;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeDistribution>
 */
class FeeDistributionFactory extends Factory
{
    protected $model = FeeDistribution::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'restaurant_id' => Restaurant::factory(),
            'user_id' => User::factory(),
            'role' => RevenueRole::Overseer,
            'percent' => 90,
            'amount_cents' => $this->faker->numberBetween(1, 5000),
            'earned_at' => now(),
        ];
    }
}
