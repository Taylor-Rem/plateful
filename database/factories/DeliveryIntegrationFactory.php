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
     * A provisioned Uber Direct integration: platform-authenticated (umbrella
     * model), so it holds no per-restaurant credentials — only the
     * sub-organization id Plateful provisioned, stored as `customer_id`, which
     * is what UberDirectProvider keys on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'provider' => DeliveryProviderName::Uber,
            'customer_id' => fake()->uuid(),
            'status' => DeliveryIntegrationStatus::Connected,
        ];
    }

    /**
     * A provisioned DoorDash Drive integration — same umbrella shape, keyed on
     * the Business/Store ids Plateful minted. `external_store_id` is what
     * DoorDashProvider keys on.
     */
    public function doordash(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => DeliveryProviderName::DoorDash,
            'customer_id' => null,
            'external_business_id' => 'biz_'.Str::random(12),
            'external_store_id' => 'store_'.Str::random(12),
            'status' => DeliveryIntegrationStatus::Connected,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeliveryIntegrationStatus::Disconnected,
        ]);
    }

    public function errored(string $message = 'Uber organization provisioning failed.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeliveryIntegrationStatus::Error,
            'last_error' => $message,
        ]);
    }
}
