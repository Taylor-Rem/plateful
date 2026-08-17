<?php

namespace Database\Factories;

use App\Models\StoryPublishOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryPublishOverride>
 */
class StoryPublishOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(3),
            'published' => true,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(['published' => false]);
    }
}
