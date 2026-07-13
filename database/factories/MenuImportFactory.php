<?php

namespace Database\Factories;

use App\Enums\MenuImportStatus;
use App\Models\MenuImport;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuImport>
 */
class MenuImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'status' => MenuImportStatus::Queued,
            'file_paths' => ['restaurants/1/menu-imports/test/page-1.webp'],
        ];
    }

    public function needsReview(): static
    {
        return $this->state(fn () => [
            'status' => MenuImportStatus::NeedsReview,
            'result' => [
                'categories' => [
                    [
                        'name' => 'Tacos',
                        'items' => [
                            [
                                'name' => 'Carne Asada Taco',
                                'description' => 'Grilled steak, onion, cilantro.',
                                'price_cents' => 399,
                                'price_note' => null,
                            ],
                            [
                                'name' => 'Baja Fish Taco',
                                'description' => null,
                                'price_cents' => 499,
                                'price_note' => 'Two sizes: $4.99 / $7.99 — imported large',
                            ],
                        ],
                    ],
                ],
                'warnings' => [],
            ],
            'model' => 'claude-opus-4-8',
            'input_tokens' => 4200,
            'output_tokens' => 900,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => MenuImportStatus::Processing]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => MenuImportStatus::Failed,
            'error' => 'We couldn’t read a menu from those files.',
        ]);
    }
}
