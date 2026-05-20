<?php

use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;

const MIT_BASE = 'http://admin.plateful.test';

function mitRestaurant(string $sub): Restaurant
{
    return Restaurant::create([
        'name' => "R-{$sub}",
        'subdomain' => $sub,
        'email' => "hello@{$sub}.test",
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

function mitAdmin(Restaurant $r): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id);

    return $u;
}

function mitCategory(Restaurant $r): MenuCategory
{
    return MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas',
        'position' => 0,
        'is_active' => true,
    ]);
}

/**
 * Build a pizza template (Size required 1-of-1, Toppings unlimited).
 *
 * @return array{template: ItemTemplate, size: ItemTemplateGroup, toppings: ItemTemplateGroup, small: ItemTemplateOption, medium: ItemTemplateOption, pepperoni: ItemTemplateOption, bacon: ItemTemplateOption}
 */
function mitPizzaTemplate(Restaurant $r): array
{
    $tpl = ItemTemplate::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizza',
        'is_active' => true,
        'position' => 0,
    ]);
    $size = ItemTemplateGroup::create([
        'item_template_id' => $tpl->id,
        'name' => 'Size',
        'min_selections' => 1,
        'max_selections' => 1,
        'position' => 0,
    ]);
    $small = ItemTemplateOption::create(['item_template_group_id' => $size->id, 'name' => 'Small', 'price_delta_cents' => -200, 'is_available' => true, 'position' => 0]);
    $medium = ItemTemplateOption::create(['item_template_group_id' => $size->id, 'name' => 'Medium', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 1]);

    $toppings = ItemTemplateGroup::create([
        'item_template_id' => $tpl->id,
        'name' => 'Toppings',
        'min_selections' => 0,
        'max_selections' => null,
        'position' => 1,
    ]);
    $pepperoni = ItemTemplateOption::create(['item_template_group_id' => $toppings->id, 'name' => 'Pepperoni', 'price_delta_cents' => 200, 'is_available' => true, 'position' => 0]);
    $bacon = ItemTemplateOption::create(['item_template_group_id' => $toppings->id, 'name' => 'Bacon', 'price_delta_cents' => 300, 'is_available' => true, 'position' => 1]);

    return compact('tpl', 'size', 'toppings', 'small', 'medium', 'pepperoni', 'bacon') + ['template' => $tpl];
}

test('creating an item with valid default selections works', function () {
    $r = mitRestaurant('marcos');
    $admin = mitAdmin($r);
    $cat = mitCategory($r);
    $bundle = mitPizzaTemplate($r);

    $this->actingAs($admin)
        ->post(MIT_BASE.'/marcos/menu/items', [
            'name' => 'Pepperoni Pizza',
            'menu_category_id' => $cat->id,
            'price' => '14.00',
            'is_available' => true,
            'item_template_id' => $bundle['template']->id,
            'default_selection_ids' => [$bundle['medium']->id, $bundle['pepperoni']->id],
        ])
        ->assertRedirect();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    expect($item)->not->toBeNull()
        ->and($item->item_template_id)->toBe($bundle['template']->id)
        ->and($item->defaultSelections()->pluck('item_template_options.id')->sort()->values()->all())
        ->toEqual(collect([$bundle['medium']->id, $bundle['pepperoni']->id])->sort()->values()->all());
});

test('defaults violating min_selections fail with group-named message', function () {
    $r = mitRestaurant('marcos');
    $admin = mitAdmin($r);
    $cat = mitCategory($r);
    $bundle = mitPizzaTemplate($r);

    // Size requires 1; provide 0 defaults from Size.
    $response = $this->actingAs($admin)
        ->post(MIT_BASE.'/marcos/menu/items', [
            'name' => 'Bad Pizza',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'item_template_id' => $bundle['template']->id,
            'default_selection_ids' => [],
        ]);

    $response->assertSessionHasErrors('default_selection_ids');
    $errors = session('errors')->get('default_selection_ids');
    expect(implode(' ', $errors))->toContain('Size');
});

test('defaults exceeding max_selections fail', function () {
    $r = mitRestaurant('marcos');
    $admin = mitAdmin($r);
    $cat = mitCategory($r);
    $bundle = mitPizzaTemplate($r);

    // Size is 1-of-1; sending 2 size options exceeds max.
    $this->actingAs($admin)
        ->post(MIT_BASE.'/marcos/menu/items', [
            'name' => 'Pizza',
            'menu_category_id' => $cat->id,
            'price' => '14.00',
            'is_available' => true,
            'item_template_id' => $bundle['template']->id,
            'default_selection_ids' => [$bundle['small']->id, $bundle['medium']->id],
        ])
        ->assertSessionHasErrors('default_selection_ids');
});

test('defaults referencing options from another template fail', function () {
    $r = mitRestaurant('marcos');
    $admin = mitAdmin($r);
    $cat = mitCategory($r);
    $bundle = mitPizzaTemplate($r);

    // Build an unrelated template + option.
    $other = ItemTemplate::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Salad',
        'is_active' => true,
        'position' => 0,
    ]);
    $g = ItemTemplateGroup::create([
        'item_template_id' => $other->id,
        'name' => 'Dressing',
        'min_selections' => 0,
        'max_selections' => null,
        'position' => 0,
    ]);
    $strangerOption = ItemTemplateOption::create([
        'item_template_group_id' => $g->id,
        'name' => 'Ranch',
        'price_delta_cents' => 0,
        'is_available' => true,
        'position' => 0,
    ]);

    $this->actingAs($admin)
        ->post(MIT_BASE.'/marcos/menu/items', [
            'name' => 'Pizza',
            'menu_category_id' => $cat->id,
            'price' => '14.00',
            'is_available' => true,
            'item_template_id' => $bundle['template']->id,
            'default_selection_ids' => [$bundle['medium']->id, $strangerOption->id],
        ])
        ->assertSessionHasErrors('default_selection_ids');
});

test('switching an item to a different template clears old default selections', function () {
    $r = mitRestaurant('marcos');
    $admin = mitAdmin($r);
    $cat = mitCategory($r);
    $bundle = mitPizzaTemplate($r);

    $item = MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'item_template_id' => $bundle['template']->id,
        'name' => 'Pep',
        'slug' => 'pep',
        'price_cents' => 1400,
        'is_available' => true,
        'position' => 0,
    ]);
    $item->defaultSelections()->sync([$bundle['medium']->id, $bundle['pepperoni']->id]);

    // Build the salad template with one required group.
    $salad = ItemTemplate::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Salad',
        'is_active' => true,
        'position' => 0,
    ]);
    $sg = ItemTemplateGroup::create([
        'item_template_id' => $salad->id,
        'name' => 'Dressing',
        'min_selections' => 1,
        'max_selections' => 1,
        'position' => 0,
    ]);
    $ranch = ItemTemplateOption::create([
        'item_template_group_id' => $sg->id,
        'name' => 'Ranch',
        'price_delta_cents' => 0,
        'is_available' => true,
        'position' => 0,
    ]);

    $this->actingAs($admin)
        ->put(MIT_BASE."/marcos/menu/items/{$item->id}", [
            'name' => 'Salad Bowl',
            'menu_category_id' => $cat->id,
            'price' => '9.00',
            'is_available' => true,
            'item_template_id' => $salad->id,
            'default_selection_ids' => [$ranch->id],
        ])
        ->assertRedirect();

    $item->refresh();
    expect($item->item_template_id)->toBe($salad->id)
        ->and($item->defaultSelections()->pluck('item_template_options.id')->all())->toEqual([$ranch->id]);
});
