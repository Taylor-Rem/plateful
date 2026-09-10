<?php

use App\Enums\RestaurantRole;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use Pest\Browser\Playwright\Playwright;

// The admin console is domain-routed; present every request with the admin host.
beforeEach(function () {
    Playwright::setHost('admin.plateful.test');
    config(['platform.primary_domain' => 'plateful.test']);
});

/**
 * @return array{admin: User, item: MenuItem}
 */
function ingredientsBrowserFixture(): array
{
    $restaurant = Restaurant::create([
        'name' => 'Browser Deli',
        'subdomain' => 'browserdeli',
        'email' => 'hello@browserdeli.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Admin->value]);

    $category = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id, 'name' => 'Sandwiches', 'slug' => 'sandwiches', 'position' => 0, 'is_active' => true,
    ]);
    $item = MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id, 'menu_category_id' => $category->id,
        'name' => 'Classic Italian', 'slug' => 'classic-italian',
        'description' => 'Cotto salami, mortadella, provolone cheese, lettuce, and mayo. Hot by request.',
        'price_cents' => 1050, 'is_available' => true, 'position' => 0,
    ]);

    return ['admin' => $admin, 'item' => $item];
}

test('an admin splits ingredients from the description, prices an extra, and saves', function () {
    ['admin' => $admin, 'item' => $item] = ingredientsBrowserFixture();
    $this->actingAs($admin);

    $page = visit('/browserdeli/menu')
        ->assertNoJavaScriptErrors()
        ->assertSee('Classic Italian')
        ->assertDontSee('Configurable');

    $page->click('[aria-label="Ingredients for Classic Italian"]')
        ->assertSee('Split from description')
        ->click('Split from description')
        ->assertValue('[aria-label="Ingredient 1 name"]', 'Cotto salami')
        ->assertValue('[aria-label="Ingredient 2 name"]', 'Mortadella')
        ->assertValue('[aria-label="Ingredient 5 name"]', 'Mayo')
        // "Hot by request." is a note, not an ingredient.
        ->assertDontSee('Hot by request')
        ->fill('[aria-label="Extra Mortadella price"]', '1.50')
        ->click('Save ingredients')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertSee('Saved ingredients');

    $item = $item->fresh();
    expect($item->ingredients->pluck('name')->all())->toBe(['Cotto salami', 'Mortadella', 'Provolone cheese', 'Lettuce', 'Mayo'])
        ->and($item->ingredients->firstWhere('name', 'Mortadella')->extra_price_cents)->toBe(150)
        ->and($item->optionGroups()->pluck('kind')->all())->toBe(['included', 'extras']);
});

test('the customer preview shows the compiled sections', function () {
    ['admin' => $admin, 'item' => $item] = ingredientsBrowserFixture();
    $this->actingAs($admin);

    visit('/browserdeli/menu')
        ->click('[aria-label="Ingredients for Classic Italian"]')
        ->click('Split from description')
        ->click('Save ingredients')
        ->wait(1)
        ->click('Preview as customer')
        ->assertSee('Leave anything out?')
        ->assertSee('Uncheck to leave out')
        ->assertSee('Close preview')
        ->assertDontSee('Add to cart')
        ->assertNoJavaScriptErrors();
});

test('the storefront edit mode drawer carries the same panel', function () {
    ['admin' => $admin, 'item' => $item] = ingredientsBrowserFixture();
    $this->actingAs($admin);

    Playwright::setHost('browserdeli.plateful.test');

    visit('/menu')
        ->assertNoJavaScriptErrors()
        ->click('Edit mode')
        ->click('Classic Italian')
        ->assertSee('Ingredients')
        ->click('Split from description')
        ->assertValue('[aria-label="Ingredient 2 name"]', 'Mortadella')
        ->click('Save ingredients')
        ->wait(1)
        ->assertSee('Saved ingredients')
        ->assertNoJavaScriptErrors();

    expect($item->fresh()->ingredients)->toHaveCount(5)
        ->and($item->fresh()->optionGroups()->pluck('kind')->all())->toBe(['included']);
});
