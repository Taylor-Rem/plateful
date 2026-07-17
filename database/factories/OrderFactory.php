<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(500, 5000);

        return [
            'restaurant_id' => Restaurant::factory(),
            'user_id' => null,
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->safeEmail(),
            'number' => strtoupper(Str::random(3)).'-'.strtoupper(Str::random(5)),
            'status' => OrderStatus::Pending,
            'type' => OrderType::Pickup,
            'subtotal_cents' => $subtotal,
            'tax_cents' => 0,
            'tip_cents' => 0,
            'delivery_fee_cents' => 0,
            'application_fee_cents' => (int) floor($subtotal * 0.04),
            // Historically the Stripe fee WAS the commission; mirror that so
            // factory orders drive the revenue split like real pickup orders.
            'platform_commission_cents' => (int) floor($subtotal * 0.04),
            'delivery_margin_cents' => 0,
            'courier_fee_cents' => 0,
            'total_cents' => $subtotal,
            'placed_at' => now(),
            'confirmation_token' => Str::random(64),
        ];
    }
}
