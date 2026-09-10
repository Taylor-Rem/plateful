<?php

use App\Enums\RestaurantRole;
use App\Models\ItemTemplate;
use App\Models\MenuCategory;
use App\Models\MenuImport;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\MenuExtractionService;
use Pest\Browser\Playwright\Playwright;

beforeEach(function () {
    Playwright::setHost('admin.plateful.test');
    config(['platform.primary_domain' => 'plateful.test']);
});

/**
 * @return array{admin: User, restaurant: Restaurant}
 */
function wizardFixture(): array
{
    $restaurant = Restaurant::factory()->approved()->create(['is_active' => true, 'subdomain' => 'wizarddeli', 'onboarding_completed_at' => now()]);
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Admin->value]);

    return ['admin' => $admin, 'restaurant' => $restaurant];
}

test('the import wizard step accepts a swap suggestion, prices an extra, and confirms', function () {
    ['admin' => $admin, 'restaurant' => $restaurant] = wizardFixture();
    $import = MenuImport::factory()->needsReview()->create([
        'restaurant_id' => $restaurant->id,
        'file_paths' => [],
        'result' => [
            'categories' => [[
                'name' => 'Sandwiches',
                'items' => [[
                    'name' => 'Classic Italian', 'description' => 'Cotto salami, mortadella, provolone.', 'price_cents' => 1050, 'price_note' => null, 'option_set' => null,
                    'ingredients' => ['Cotto salami', 'Mortadella', 'Provolone'],
                    'suggested_customizations' => [
                        ['name' => 'Cheese', 'kind' => 'swap', 'swap_options' => ['Provolone', 'Mozzarella'], 'reason' => 'common request'],
                    ],
                ]],
            ]],
            'option_sets' => [],
            'warnings' => [],
        ],
    ]);
    $this->actingAs($admin);

    visit("/wizarddeli/menu-import/{$import->id}/review")
        ->assertNoJavaScriptErrors()
        ->assertSee('Step 1 of 2')
        ->click('[data-test="next-step-button"]')
        ->assertSee('What can customers change?')
        ->assertSee('Suggested by Plateful')
        ->assertValue('[aria-label="Ingredient 2 name"]', 'Mortadella')
        ->fill('[aria-label="Double Mortadella price"]', '1.50')
        ->click('Accept')
        ->assertDontSee('common request')
        ->fill('[aria-label="Swap option 2 price difference"]', '0.75')
        ->click('[data-test="confirm-import-button"]')
        ->wait(2)
        ->assertNoJavaScriptErrors();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $restaurant->id)->where('name', 'Classic Italian')->sole();
    $cheese = ItemTemplate::withoutTenantScope()->where('restaurant_id', $restaurant->id)->where('name', 'Cheese')->sole();

    // The swap proposal "Cheese" attaches to the printed ingredient it
    // replaces (Provolone) — no stray "Cheese" row, no extra $0 option.
    expect($item->ingredients->pluck('name')->all())->toBe(['Cotto salami', 'Mortadella', 'Provolone'])
        ->and($item->ingredients->firstWhere('name', 'Mortadella')->extra_price_cents)->toBe(150)
        ->and($item->ingredients->firstWhere('name', 'Provolone')->swap_template_id)->toBe($cheese->id)
        ->and($cheese->groups->first()->options->pluck('name')->all())->toBe(['Provolone', 'Mozzarella'])
        ->and($cheese->groups->first()->options->pluck('price_delta_cents')->all())->toBe([0, 75])
        ->and($item->optionGroups()->firstWhere('name', 'Mortadella')->options->pluck('price_delta_cents')->all())->toBe([0, 0, 0, 150])
        ->and($item->optionGroups()->pluck('kind')->all())->toBe(['swap', 'ingredient', 'ingredient', 'ingredient']);
});

test('the Ingredients panel shows Plateful suggestions and accepts one', function () {
    ['admin' => $admin, 'restaurant' => $restaurant] = wizardFixture();
    $category = MenuCategory::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sandwiches', 'slug' => 'sandwiches', 'position' => 0, 'is_active' => true]);
    $item = MenuItem::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'menu_category_id' => $category->id, 'name' => 'Classic Italian', 'slug' => 'classic-italian', 'description' => 'Cotto salami, mortadella.', 'price_cents' => 1050, 'is_available' => true, 'position' => 0]);

    $this->mock(MenuExtractionService::class)
        ->shouldReceive('suggestCustomizations')
        ->once()
        ->andReturn([
            'ingredients' => ['Cotto salami', 'Mortadella', 'Provolone'],
            'suggested_customizations' => [
                ['name' => 'Avocado', 'kind' => 'extra', 'swap_options' => [], 'reason' => 'popular add-on'],
            ],
            'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1,
        ]);

    $this->actingAs($admin);

    visit('/wizarddeli/menu')
        ->click('[aria-label="Ingredients for Classic Italian"]')
        ->click('Split from description')
        ->click('Suggest customizations')
        ->wait(1)
        ->assertSee('popular add-on')
        ->assertSee('Ingredients not listed yet')
        ->assertSee('Provolone')
        ->click('Accept')
        ->assertValue('[aria-label="Ingredient 3 name"]', 'Avocado')
        ->fill('[aria-label="Double Avocado price"]', '2.00')
        ->click('Save ingredients')
        ->wait(1)
        ->assertSee('Saved ingredients')
        ->assertNoJavaScriptErrors();

    $item = $item->fresh();
    $avocado = $item->ingredients->firstWhere('name', 'Avocado');
    expect($avocado->is_removable)->toBeFalse()
        ->and($avocado->extra_price_cents)->toBe(200)
        // An add-on that isn't part of the item: no None, Half or Double only around Regular.
        ->and($item->optionGroups()->firstWhere('name', 'Avocado')->options->pluck('name')->all())->toBe(['Half', 'Regular', 'Double'])
        ->and($item->optionGroups()->firstWhere('name', 'Mortadella')->options->pluck('name')->all())->toBe(['None', 'Half', 'Regular']);
});
