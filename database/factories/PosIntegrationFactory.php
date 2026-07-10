<?php

namespace Database\Factories;

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PosIntegration>
 */
class PosIntegrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'provider' => PosProviderName::Square,
            'external_merchant_id' => 'M'.fake()->numerify('########'),
            'location_id' => 'L'.fake()->numerify('####'),
            'access_token' => 'tok_'.Str::random(32),
            'refresh_token' => 'rtok_'.Str::random(32),
            'token_expires_at' => now()->addDays(30),
            'status' => PosIntegrationStatus::Connected,
            'scopes' => ['ORDERS_WRITE'],
        ];
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PosIntegrationStatus::Disconnected,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);
    }

    public function tokenExpired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PosIntegrationStatus::TokenExpired,
            'token_expires_at' => now()->subDay(),
        ]);
    }

    public function clover(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => PosProviderName::Clover,
        ]);
    }
}
