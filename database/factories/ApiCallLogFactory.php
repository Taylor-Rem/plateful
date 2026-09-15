<?php

namespace Database\Factories;

use App\Models\ApiCallLog;
use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiCallLog>
 */
class ApiCallLogFactory extends Factory
{
    protected $model = ApiCallLog::class;

    /**
     * A successful MCP call by a restaurant key, at that key's restaurant.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = ApiKey::factory();

        return [
            'api_key_id' => $key,
            'restaurant_id' => fn (array $attributes) => ApiKey::query()->find($attributes['api_key_id'])?->restaurant_id,
            'channel' => ApiCallLog::CHANNEL_MCP,
            'action' => 'get-menu',
            'arguments' => ['restaurant' => 'marcos'],
            'ok' => true,
            'error' => null,
            'duration_ms' => fake()->numberBetween(5, 400),
            'created_at' => now(),
        ];
    }

    public function failed(string $error = 'This credential lacks the menu:write scope.'): static
    {
        return $this->state(fn () => ['ok' => false, 'error' => $error]);
    }

    public function rest(string $routeName = 'api.v1.operator.restaurants.menu'): static
    {
        return $this->state(fn () => ['channel' => ApiCallLog::CHANNEL_REST, 'action' => $routeName]);
    }
}
