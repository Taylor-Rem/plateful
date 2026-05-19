<?php

use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Helper: build a Pepperoni Pizza item priced at $14 with defaults
 * Size:Medium ($0) + Pepperoni (+$2). Base cents = 1200 + 200 = 1400.
 *
 * @return array{item: MenuItem, small: ItemTemplateOption, medium: ItemTemplateOption, large: ItemTemplateOption, pepperoni: ItemTemplateOption, bacon: ItemTemplateOption}
 */
function pricingFixture(): array
{
    $r = Restaurant::create([
        'name' => 'R', 'subdomain' => 'r', 'email' => 'r@r.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id, 'name' => 'P', 'slug' => 'p', 'position' => 0, 'is_active' => true,
    ]);
    $tpl = ItemTemplate::withoutTenantScope()->create([
        'restaurant_id' => $r->id, 'name' => 'Pizza', 'is_active' => true, 'position' => 0,
    ]);
    $sz = ItemTemplateGroup::create([
        'item_template_id' => $tpl->id, 'name' => 'Size', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0,
    ]);
    $small = ItemTemplateOption::create(['item_template_group_id' => $sz->id, 'name' => 'Small', 'price_delta_cents' => -200, 'is_available' => true, 'position' => 0]);
    $medium = ItemTemplateOption::create(['item_template_group_id' => $sz->id, 'name' => 'Medium', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 1]);
    $large = ItemTemplateOption::create(['item_template_group_id' => $sz->id, 'name' => 'Large', 'price_delta_cents' => 300, 'is_available' => true, 'position' => 2]);

    $tp = ItemTemplateGroup::create([
        'item_template_id' => $tpl->id, 'name' => 'Toppings', 'min_selections' => 0, 'max_selections' => null, 'position' => 1,
    ]);
    $pepperoni = ItemTemplateOption::create(['item_template_group_id' => $tp->id, 'name' => 'Pepperoni', 'price_delta_cents' => 200, 'is_available' => true, 'position' => 0]);
    $bacon = ItemTemplateOption::create(['item_template_group_id' => $tp->id, 'name' => 'Bacon', 'price_delta_cents' => 300, 'is_available' => true, 'position' => 1]);

    $item = MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'item_template_id' => $tpl->id,
        'name' => 'Pep', 'slug' => 'pep',
        'price_cents' => 1400, 'is_available' => true, 'position' => 0,
    ]);
    $item->defaultSelections()->sync([$medium->id, $pepperoni->id]);

    return compact('item', 'small', 'medium', 'large', 'pepperoni', 'bacon');
}

test('returns base price when selections match defaults', function () {
    $f = pricingFixture();
    expect($f['item']->priceForSelectionsCents([$f['medium']->id, $f['pepperoni']->id]))->toBe(1400);
});

test('returns base + delta when an extra option is added', function () {
    $f = pricingFixture();
    expect($f['item']->priceForSelectionsCents([$f['medium']->id, $f['pepperoni']->id, $f['bacon']->id]))->toBe(1700);
});

test('returns base - delta when a default is unchecked', function () {
    $f = pricingFixture();
    // Unchecking pepperoni (+$2 default) reduces price by 200.
    expect($f['item']->priceForSelectionsCents([$f['medium']->id]))->toBe(1200);
});

test('composite math: swap size and toppings', function () {
    $f = pricingFixture();
    // Defaults: Medium ($0) + Pepperoni (+$2). Switch to Large (+$3) and Bacon (+$3) only.
    // Adds: Large (+300) + Bacon (+300) = +600
    // Removes: Medium (0) + Pepperoni (200) = -200
    // 1400 + 600 - 200 = 1800.
    expect($f['item']->priceForSelectionsCents([$f['large']->id, $f['bacon']->id]))->toBe(1800);
});

test('unknown option id throws', function () {
    $f = pricingFixture();
    $f['item']->priceForSelectionsCents([$f['medium']->id, 999999]);
})->throws(InvalidArgumentException::class);
