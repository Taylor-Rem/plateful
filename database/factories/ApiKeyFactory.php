<?php

namespace Database\Factories;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * A restaurant key with every restaurant scope. The plaintext is not
     * recoverable from a factory row; use `ApiKey::mint()` (or the
     * `apiKeyFor()` test helper) when a test needs to send the key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = ApiKey::prefix().Str::random(40);

        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->words(2, true),
            'key_prefix' => ApiKey::visiblePrefix($plain),
            'key_hash' => ApiKey::hashKey($plain),
            'scopes' => array_map(fn (ApiKeyScope $s) => $s->value, ApiKeyScope::restaurantScopes()),
        ];
    }

    public function platform(): static
    {
        return $this->state(fn () => [
            'restaurant_id' => null,
            'scopes' => [ApiKeyScope::All->value],
        ]);
    }

    /**
     * @param  array<int, ApiKeyScope>  $scopes
     */
    public function scopes(array $scopes): static
    {
        return $this->state(fn () => [
            'scopes' => array_map(fn (ApiKeyScope $s) => $s->value, $scopes),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subMinute()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
