<?php

namespace Database\Factories;

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();
        $subdomain = Str::slug($name).'-'.Str::lower(Str::random(4));

        return [
            'name' => $name,
            'subdomain' => $subdomain,
            'custom_domain' => null,
            'description' => fake()->sentence(),
            'primary_color' => '#'.fake()->hexColor(),
            'secondary_color' => '#ffffff',
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'NY',
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'timezone' => 'America/New_York',
            'is_active' => true,
            'status' => RestaurantStatus::Active,
            'tax_rate_percent' => 0,
            'delivery_fee_cents' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'status' => RestaurantStatus::PendingReview,
            'approved_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => RestaurantStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => RestaurantStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
