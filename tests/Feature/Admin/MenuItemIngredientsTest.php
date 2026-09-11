<?php

use App\Enums\RestaurantRole;
use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

/**
 * @return array{restaurant: Restaurant, admin: User, category: MenuCategory, item: MenuItem, sibling: MenuItem, cheeses: ItemTemplate, provolone: ItemTemplateOption}
 */
function ingredientsFixture(string $sub = 'marcos'): array
{
    $r = Restaurant::create([
        'name' => "R-{$sub}", 'subdomain' => $sub, 'email' => "hello@{$sub}.test",
        'street' => '1 Main', 'city' => 'NYC', 'state' => 'NY', 'postal_code' => '10001',
    ]);
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($r->id, ['role' => RestaurantRole::Admin->value]);

    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id, 'name' => 'Sandwiches', 'slug' => 'sandwiches', 'position' => 0, 'is_active' => true,
    ]);
    $item = MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id, 'menu_category_id' => $cat->id, 'name' => 'Classic Italian', 'slug' => 'classic',
        'description' => 'Cotto salami, mortadella, provolone, lettuce, and mayo', 'price_cents' => 1050, 'is_available' => true, 'position' => 0,
    ]);
    $sibling = MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id, 'menu_category_id' => $cat->id, 'name' => 'Turkey', 'slug' => 'turkey',
        'description' => 'Turkey, provolone, lettuce, and mayo', 'price_cents' => 1100, 'is_available' => true, 'position' => 1,
    ]);

    $cheeses = ItemTemplate::withoutTenantScope()->create(['restaurant_id' => $r->id, 'name' => 'Cheeses', 'is_active' => true, 'position' => 0]);
    $g = ItemTemplateGroup::create(['item_template_id' => $cheeses->id, 'name' => 'Cheese', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0]);
    $provolone = ItemTemplateOption::create(['item_template_group_id' => $g->id, 'name' => 'Provolone', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 0]);
    ItemTemplateOption::create(['item_template_group_id' => $g->id, 'name' => 'Mozzarella', 'price_delta_cents' => 75, 'is_available' => true, 'position' => 1]);

    return ['restaurant' => $r, 'admin' => $admin, 'category' => $cat, 'item' => $item, 'sibling' => $sibling, 'cheeses' => $cheeses, 'provolone' => $provolone];
}

test('saving ingredients from the storefront editor writes rows in order and compiles the groups', function () {
    $f = ingredientsFixture();

    $this->actingAs($f['admin'])
        ->put("http://marcos.plateful.test/admin/menu/items/{$f['item']->id}/ingredients", [
            'ingredients' => [
                ['name' => 'Cotto salami', 'is_removable' => true, 'extra_price' => ''],
                ['name' => 'Mortadella', 'is_removable' => true, 'extra_price' => '1.50'],
                ['name' => 'Provolone', 'is_removable' => true, 'extra_price' => null, 'swap_template_id' => $f['cheeses']->id],
                ['name' => 'Mayo', 'is_removable' => false],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $item = $f['item']->fresh();
    expect($item->ingredients->pluck('name')->all())->toBe(['Cotto salami', 'Mortadella', 'Provolone', 'Mayo'])
        ->and($item->ingredients->firstWhere('name', 'Mortadella')->extra_price_cents)->toBe(150)
        ->and($item->ingredients->firstWhere('name', 'Mayo')->is_removable)->toBeFalse();

    $groups = $item->optionGroups();
    expect($groups->pluck('kind')->all())->toBe(['swap', 'ingredient', 'ingredient', 'ingredient', 'ingredient'])
        ->and($groups->firstWhere('name', 'Mortadella')->options->pluck('name')->all())->toBe(['None', 'Half', 'Regular', 'Double'])
        ->and($groups->firstWhere('name', 'Mortadella')->options->firstWhere('name', 'Double')->price_delta_cents)->toBe(150)
        // Not removable: no None; Half stays on by default.
        ->and($groups->firstWhere('name', 'Mayo')->options->pluck('name')->all())->toBe(['Half', 'Regular'])
        ->and($item->defaultSelections()->pluck('item_template_options.id')->all())->toContain($f['provolone']->id);
});

test('re-saving keeps rows by id, deletes the rest, and preserves generated option ids', function () {
    $f = ingredientsFixture();
    $url = "http://marcos.plateful.test/admin/menu/items/{$f['item']->id}/ingredients";

    $this->actingAs($f['admin'])->put($url, ['ingredients' => [
        ['name' => 'Cotto salami', 'is_removable' => true],
        ['name' => 'Mortadella', 'is_removable' => true],
    ]]);

    $item = $f['item']->fresh();
    $salami = $item->ingredients->firstWhere('name', 'Cotto salami');
    $salamiOptionId = $item->optionGroups()->firstWhere('name', 'Cotto salami')->options->firstWhere('name', 'Regular')->id;

    $this->actingAs($f['admin'])->put($url, ['ingredients' => [
        ['id' => $salami->id, 'name' => 'Cotto Salami', 'is_removable' => true, 'extra_price' => '2.00'],
        ['name' => 'Lettuce', 'is_removable' => true],
    ]])->assertSessionHasNoErrors();

    $item = $f['item']->fresh();
    expect($item->ingredients->pluck('name')->all())->toBe(['Cotto Salami', 'Lettuce'])
        ->and($item->ingredients->firstWhere('name', 'Cotto Salami')->id)->toBe($salami->id)
        ->and($item->optionGroups()->firstWhere('name', 'Cotto Salami')->options->firstWhere('name', 'Regular')->id)->toBe($salamiOptionId)
        ->and(MenuItemIngredient::where('menu_item_id', $item->id)->where('name', 'Mortadella')->exists())->toBeFalse();
});

test('a swap template that is not a swap set, or from another tenant, is rejected', function () {
    $f = ingredientsFixture();
    $other = ingredientsFixture('bobs');

    $toppings = ItemTemplate::withoutTenantScope()->create(['restaurant_id' => $f['restaurant']->id, 'name' => 'Toppings', 'is_active' => true, 'position' => 1]);
    ItemTemplateGroup::create(['item_template_id' => $toppings->id, 'name' => 'Toppings', 'min_selections' => 0, 'max_selections' => null, 'position' => 0]);

    $url = "http://marcos.plateful.test/admin/menu/items/{$f['item']->id}/ingredients";

    $this->actingAs($f['admin'])
        ->put($url, ['ingredients' => [['name' => 'Provolone', 'swap_template_id' => $toppings->id]]])
        ->assertSessionHasErrors('ingredients.0.swap_template_id');

    $this->actingAs($f['admin'])
        ->put($url, ['ingredients' => [['name' => 'Provolone', 'swap_template_id' => $other['cheeses']->id]]])
        ->assertSessionHasErrors('ingredients.0.swap_template_id');

    expect($f['item']->fresh()->ingredients)->toHaveCount(0);
});

test('staff cannot edit ingredients', function () {
    $f = ingredientsFixture();
    $staff = User::factory()->create();
    $staff->restaurants()->attach($f['restaurant']->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->put("http://marcos.plateful.test/admin/menu/items/{$f['item']->id}/ingredients", ['ingredients' => [['name' => 'X']]])
        ->assertForbidden();
});

test('the admin console saves ingredients through the same controller', function () {
    $f = ingredientsFixture();

    $this->actingAs($f['admin'])
        ->put("http://admin.plateful.test/marcos/menu/items/{$f['item']->id}/ingredients", [
            'ingredients' => [['name' => 'Lettuce', 'is_removable' => true, 'extra_price' => '0.50']],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($f['item']->fresh()->optionGroups()->firstWhere('name', 'Lettuce')->options->pluck('name')->all())->toBe(['None', 'Half', 'Regular', 'Double']);
});

test('an item from another tenant is not reachable through the admin console', function () {
    $f = ingredientsFixture();
    $other = ingredientsFixture('bobs');

    $this->actingAs($f['admin'])
        ->put("http://admin.plateful.test/marcos/menu/items/{$other['item']->id}/ingredients", ['ingredients' => [['name' => 'X']]])
        ->assertNotFound();
});

test('quick-creating a swap set makes a single pick-one template and flashes its id', function () {
    $f = ingredientsFixture();

    $this->actingAs($f['admin'])
        ->post('http://marcos.plateful.test/admin/menu/swap-sets', [
            'name' => 'Breads',
            'allow_none' => false,
            'options' => [
                ['name' => 'White', 'price_delta' => ''],
                ['name' => 'Wheat', 'price_delta' => '0'],
                ['name' => 'Gluten-free', 'price_delta' => '2.00'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('createdSwapSetId');

    $set = ItemTemplate::withoutTenantScope()->where('restaurant_id', $f['restaurant']->id)->where('name', 'Breads')->sole();
    expect($set->isSwapSet())->toBeTrue()
        ->and($set->groups->first()->kind)->toBe('swap')
        ->and($set->groups->first()->min_selections)->toBe(1)
        ->and($set->groups->first()->options->pluck('price_delta_cents')->all())->toBe([0, 0, 200]);
});

test('applying rules to a category updates matching ingredients by name and recompiles those items', function () {
    $f = ingredientsFixture();

    foreach ([$f['item'], $f['sibling']] as $item) {
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'name' => 'Provolone', 'position' => 0, 'is_removable' => true]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'name' => 'Mayo', 'position' => 1, 'is_removable' => true]);
    }
    MenuItemIngredient::create(['menu_item_id' => $f['item']->id, 'name' => 'Mortadella', 'position' => 2, 'is_removable' => true]);

    $this->actingAs($f['admin'])
        ->post("http://marcos.plateful.test/admin/menu/categories/{$f['category']->id}/ingredient-rules", [
            'ingredients' => [
                ['name' => 'provolone', 'is_removable' => true, 'extra_price' => '1.00', 'swap_template_id' => $f['cheeses']->id],
                ['name' => 'Mortadella', 'is_removable' => true, 'extra_price' => '1.50'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Applied to 2 items in "Sandwiches".');

    foreach ([$f['item'], $f['sibling']] as $item) {
        $item = $item->fresh();
        expect($item->ingredients->firstWhere('name', 'Provolone')->extra_price_cents)->toBe(100)
            ->and($item->ingredients->firstWhere('name', 'Provolone')->swap_template_id)->toBe($f['cheeses']->id)
            ->and($item->templates()->pluck('item_templates.id')->all())->toContain($f['cheeses']->id)
            ->and($item->optionGroups()->firstWhere('name', 'Provolone')->options->firstWhere('name', 'Double')->price_delta_cents)->toBe(100);
    }

    // The sibling has no mortadella, so nothing was invented for it.
    expect($f['sibling']->fresh()->ingredients->pluck('name')->all())->toBe(['Provolone', 'Mayo']);
});
