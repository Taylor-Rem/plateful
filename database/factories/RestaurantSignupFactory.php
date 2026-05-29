<?php

namespace Database\Factories;

use App\Models\RestaurantSignup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RestaurantSignup>
 */
class RestaurantSignupFactory extends Factory
{
    protected $model = RestaurantSignup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory(),
            'restaurant_id' => null,
            'proposed_name' => $name,
            'proposed_subdomain' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'proposed_custom_domain' => null,
            'cuisine_type' => fake()->randomElement(['Pizza', 'Burgers', 'Sushi', 'Mexican', 'Thai']),
            'city' => fake()->city(),
            'state' => 'NY',
            'notes' => null,
            'status' => RestaurantSignup::STATUS_PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => RestaurantSignup::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Not a fit'): static
    {
        return $this->state(fn () => [
            'status' => RestaurantSignup::STATUS_REJECTED,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
