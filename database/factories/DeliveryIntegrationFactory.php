<?php

namespace Database\Factories;

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Models\DeliveryIntegration;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeliveryIntegration>
 */
class DeliveryIntegrationFactory extends Factory
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
            'provider' => DeliveryProviderName::Uber,
            'client_id' => 'cid_'.Str::random(24),
            'client_secret' => 'csec_'.Str::random(32),
            'customer_id' => fake()->uuid(),
            'access_token' => 'tok_'.Str::random(32),
            // Uber Direct access tokens live 30 days.
            'token_expires_at' => now()->addDays(30),
            'status' => DeliveryIntegrationStatus::Connected,
        ];
    }

    /**
     * Credentials entered but never exercised — no token minted yet.
     */
    public function withoutToken(): static
    {
        return $this->state(fn (array $attributes): array => [
            'access_token' => null,
            'token_expires_at' => null,
        ]);
    }

    public function tokenExpired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'token_expires_at' => now()->subDay(),
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeliveryIntegrationStatus::Disconnected,
            'client_id' => null,
            'client_secret' => null,
            'customer_id' => null,
            'access_token' => null,
            'token_expires_at' => null,
        ]);
    }

    public function errored(string $message = 'Uber rejected these credentials.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeliveryIntegrationStatus::Error,
            'last_error' => $message,
            'access_token' => null,
            'token_expires_at' => null,
        ]);
    }
}
