<?php

use App\Enums\MenuImportStatus;
use App\Enums\RestaurantRole;
use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuImport;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\MenuExtractionService;
use App\Support\Menus\ExtractedMenuSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const MIC_ADMIN_HOST = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

/**
 * @return array{0: User, 1: Restaurant}
 */
function micOwnerAndRestaurant(): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->approved()->create(['is_active' => true, 'subdomain' => 'deli']);
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

it('sanitizes printed ingredients and suggestions: caps, kinds, dedupe, no prices', function () {
    config(['menu_import.max_ingredients_per_item' => 3, 'menu_import.max_suggestions_per_item' => 2]);

    $result = ExtractedMenuSanitizer::sanitize([[
        'name' => 'Sandwiches',
        'items' => [[
            'name' => 'Classic Italian', 'description' => null, 'price_cents' => 1050, 'price_note' => null, 'option_set' => null,
            'ingredients' => ['Cotto salami', 'cotto salami', '  Mortadella ', 'Provolone', 'Lettuce', ''],
            'suggested_customizations' => [
                ['name' => 'Cheese', 'kind' => 'swap', 'swap_options' => ['Provolone', 'Mozzarella', 'Swiss'], 'reason' => 'common', 'price_cents' => 150],
                ['name' => 'Onions', 'kind' => 'bogus', 'swap_options' => [], 'reason' => ''],
                ['name' => 'Bread', 'kind' => 'swap', 'swap_options' => ['White'], 'reason' => 'only one option'],
                ['name' => 'Avocado', 'kind' => 'extra', 'swap_options' => ['ignored'], 'reason' => 'popular'],
                ['name' => 'Pickles', 'kind' => 'remove', 'swap_options' => [], 'reason' => 'often left out'],
            ],
        ]],
    ]]);

    $item = $result['categories'][0]['items'][0];
    expect($item['ingredients'])->toBe(['Cotto salami', 'Mortadella', 'Provolone'])
        ->and($item['suggested_customizations'])->toBe([
            ['name' => 'Cheese', 'kind' => 'swap', 'swap_options' => ['Provolone', 'Mozzarella', 'Swiss'], 'reason' => 'common'],
            ['name' => 'Avocado', 'kind' => 'extra', 'swap_options' => [], 'reason' => 'popular'],
        ]);
});

it('imports ingredient rows and inline swap sets, sharing a swap set across items by name', function () {
    [$owner, $restaurant] = micOwnerAndRestaurant();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $swapSet = ['name' => 'Cheeses', 'options' => [
        ['name' => 'Provolone', 'price_delta_cents' => 0],
        ['name' => 'Mozzarella', 'price_delta_cents' => 75],
    ]];

    $this->actingAs($owner)
        ->post(MIC_ADMIN_HOST."/deli/menu-import/{$import->id}/confirm", [
            'categories' => [[
                'name' => 'Sandwiches',
                'items' => [
                    ['name' => 'Classic Italian', 'description' => null, 'price_cents' => 1050, 'option_set' => null, 'ingredients' => [
                        ['name' => 'Cotto salami', 'is_removable' => true],
                        ['name' => 'Mortadella', 'is_removable' => true, 'extra_price_cents' => 150],
                        ['name' => 'Provolone', 'is_removable' => true, 'swap_set' => $swapSet],
                        ['name' => 'Bun', 'is_removable' => false],
                    ]],
                    ['name' => 'Turkey', 'description' => null, 'price_cents' => 1100, 'option_set' => null, 'ingredients' => [
                        ['name' => 'Turkey', 'is_removable' => false],
                        ['name' => 'Provolone', 'is_removable' => true, 'swap_set' => $swapSet],
                    ]],
                    ['name' => 'Coke', 'description' => null, 'price_cents' => 299, 'option_set' => null, 'ingredients' => []],
                ],
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $cheeses = ItemTemplate::withoutTenantScope()->where('restaurant_id', $restaurant->id)->where('name', 'Cheeses')->get();
    expect($cheeses)->toHaveCount(1)
        ->and($cheeses->first()->isSwapSet())->toBeTrue();

    $classic = $restaurant->menuItems()->where('name', 'Classic Italian')->sole();
    $groups = $classic->optionGroups();
    expect($classic->ingredients->pluck('name')->all())->toBe(['Cotto salami', 'Mortadella', 'Provolone', 'Bun'])
        ->and($groups->pluck('kind')->all())->toBe(['swap', 'included', 'extras'])
        ->and($groups->firstWhere('kind', 'included')->options->pluck('name')->all())->toBe(['Cotto salami', 'Mortadella'])
        ->and($groups->firstWhere('kind', 'extras')->options->pluck('name')->all())->toBe(['Extra Mortadella'])
        ->and($classic->templates()->pluck('item_templates.id')->all())->toBe([$cheeses->first()->id]);

    $turkey = $restaurant->menuItems()->where('name', 'Turkey')->sole();
    expect($turkey->templates()->pluck('item_templates.id')->all())->toBe([$cheeses->first()->id])
        ->and($turkey->optionGroups()->pluck('kind')->all())->toBe(['swap']);

    expect($restaurant->menuItems()->where('name', 'Coke')->sole()->optionGroups())->toHaveCount(0)
        ->and($import->fresh()->status)->toBe(MenuImportStatus::Completed);
});

it('exposes existing customizations and swap sets on the review page for re-import carry-over', function () {
    [$owner, $restaurant] = micOwnerAndRestaurant();
    $cat = MenuCategory::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'name' => 'S', 'slug' => 's', 'position' => 0, 'is_active' => true]);
    $item = MenuItem::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'menu_category_id' => $cat->id, 'name' => 'Classic Italian', 'slug' => 'ci', 'price_cents' => 1050, 'is_available' => true, 'position' => 0]);
    $cheeses = ItemTemplate::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'name' => 'Cheeses', 'is_active' => true, 'position' => 0]);
    $g = ItemTemplateGroup::create(['item_template_id' => $cheeses->id, 'name' => 'Cheese', 'kind' => 'swap', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0]);
    ItemTemplateOption::create(['item_template_group_id' => $g->id, 'name' => 'Provolone', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 0]);
    MenuItemIngredient::create(['menu_item_id' => $item->id, 'name' => 'Mortadella', 'position' => 0, 'is_removable' => true, 'extra_price_cents' => 150]);
    MenuItemIngredient::create(['menu_item_id' => $item->id, 'name' => 'Provolone', 'position' => 1, 'is_removable' => true, 'swap_template_id' => $cheeses->id]);

    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->get(MIC_ADMIN_HOST."/deli/menu-import/{$import->id}/review")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('existingCustomizations.classic italian.name', 'Classic Italian')
            ->where('existingCustomizations.classic italian.ingredients.0.extra_price_cents', 150)
            ->where('existingCustomizations.classic italian.ingredients.1.swap_template_id', $cheeses->id)
            ->where('swapSets.0.name', 'Cheeses'),
        );
});

it('re-imports carried-over rows that reference an existing swap set, and rejects a non-swap-set id', function () {
    [$owner, $restaurant] = micOwnerAndRestaurant();
    $cheeses = ItemTemplate::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'name' => 'Cheeses', 'is_active' => true, 'position' => 0]);
    $g = ItemTemplateGroup::create(['item_template_id' => $cheeses->id, 'name' => 'Cheese', 'kind' => 'swap', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0]);
    $provolone = ItemTemplateOption::create(['item_template_group_id' => $g->id, 'name' => 'Provolone', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 0]);
    $toppings = ItemTemplate::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'name' => 'Toppings', 'is_active' => true, 'position' => 1]);
    ItemTemplateGroup::create(['item_template_id' => $toppings->id, 'name' => 'Toppings', 'min_selections' => 0, 'max_selections' => null, 'position' => 0]);

    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);
    $url = MIC_ADMIN_HOST."/deli/menu-import/{$import->id}/confirm";
    $payload = fn (int $swapId) => ['categories' => [[
        'name' => 'Sandwiches',
        'items' => [['name' => 'Classic Italian', 'description' => null, 'price_cents' => 1050, 'option_set' => null, 'ingredients' => [
            ['name' => 'Provolone', 'is_removable' => true, 'swap_template_id' => $swapId],
        ]]],
    ]]];

    $this->actingAs($owner)->post($url, $payload($toppings->id))->assertSessionHasErrors('categories');

    $this->actingAs($owner)->post($url, $payload($cheeses->id))->assertSessionHasNoErrors();

    $item = $restaurant->menuItems()->where('name', 'Classic Italian')->sole();
    expect($item->templates()->pluck('item_templates.id')->all())->toBe([$cheeses->id])
        ->and($item->defaultSelections()->pluck('item_template_options.id')->all())->toContain($provolone->id)
        ->and(ItemTemplate::withoutTenantScope()->where('restaurant_id', $restaurant->id)->count())->toBe(2);
});

it('flashes sanitized suggestions for a hand-made item from both hosts', function () {
    [$owner, $restaurant] = micOwnerAndRestaurant();
    $cat = MenuCategory::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sandwiches', 'slug' => 's', 'position' => 0, 'is_active' => true]);
    $item = MenuItem::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'menu_category_id' => $cat->id, 'name' => 'Classic Italian', 'slug' => 'ci', 'description' => 'Salami and provolone', 'price_cents' => 1050, 'is_available' => true, 'position' => 0]);

    $this->mock(MenuExtractionService::class)
        ->shouldReceive('suggestCustomizations')
        ->twice()
        ->withArgs(fn ($name, $description, $category) => $name === 'Classic Italian' && $description === 'Salami and provolone' && $category === 'Sandwiches')
        ->andReturn([
            'ingredients' => ['Salami', 'Provolone'],
            'suggested_customizations' => [
                ['name' => 'Cheese', 'kind' => 'swap', 'swap_options' => ['Provolone', 'Mozzarella'], 'reason' => 'common', 'price_cents' => 999],
                ['name' => 'Bad', 'kind' => 'nope', 'swap_options' => [], 'reason' => ''],
            ],
            'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1,
        ]);

    $this->actingAs($owner)
        ->post(MIC_ADMIN_HOST."/deli/menu/items/{$item->id}/suggestions")
        ->assertRedirect()
        ->assertSessionHas('itemSuggestions', fn ($flash) => $flash['menuItemId'] === $item->id
            && $flash['ingredients'] === ['Salami', 'Provolone']
            && count($flash['suggestions']) === 1
            && ! array_key_exists('price_cents', $flash['suggestions'][0]));

    $this->actingAs($owner)
        ->post("http://deli.plateful.test/admin/menu/items/{$item->id}/suggestions")
        ->assertRedirect()
        ->assertSessionHas('itemSuggestions');
});

it('reports a friendly error when the suggestion call fails', function () {
    [$owner, $restaurant] = micOwnerAndRestaurant();
    $cat = MenuCategory::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'name' => 'S', 'slug' => 's', 'position' => 0, 'is_active' => true]);
    $item = MenuItem::withoutTenantScope()->create(['restaurant_id' => $restaurant->id, 'menu_category_id' => $cat->id, 'name' => 'X', 'slug' => 'x', 'price_cents' => 100, 'is_available' => true, 'position' => 0]);

    $this->mock(MenuExtractionService::class)
        ->shouldReceive('suggestCustomizations')
        ->once()
        ->andThrow(new RuntimeException('boom'));

    $this->actingAs($owner)
        ->post(MIC_ADMIN_HOST."/deli/menu/items/{$item->id}/suggestions")
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionMissing('itemSuggestions');
});
