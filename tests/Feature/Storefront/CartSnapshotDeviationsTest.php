<?php

use App\Data\CartItemData;
use App\Data\OrderItemData;
use App\Models\CartItem;
use App\Models\MenuItemIngredient;
use App\Models\OrderItem;
use App\Services\CartManager;
use App\Support\Menus\IngredientGroupCompiler;
use App\Support\Menus\ModifierSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/CartTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

/**
 * The cart fixture's pizza plus three removable ingredients (Basil priced
 * for Double), compiled into level rows. Returns the fixture plus the
 * level option ids: $levels['Basil']['Double'].
 *
 * @return array{f: array<string, mixed>, levels: array<string, array<string, int>>, regular: array<int, int>}
 */
function deviationFixture(): array
{
    $f = cartFixture();
    foreach ([['Mozzarella', null], ['Basil', 150], ['Tomato sauce', null]] as $i => [$name, $extra]) {
        MenuItemIngredient::create(['menu_item_id' => $f['item']->id, 'name' => $name, 'position' => $i, 'is_removable' => true, 'extra_price_cents' => $extra]);
    }
    app(IngredientGroupCompiler::class)->compile($f['item']->fresh());

    $levels = [];
    foreach ($f['item']->fresh()->optionGroups()->where('kind', 'ingredient') as $row) {
        $levels[$row->name] = $row->options->pluck('id', 'name')->map(fn ($id) => (int) $id)->all();
    }

    return [
        'f' => $f,
        'levels' => $levels,
        'regular' => array_values(array_map(fn ($row) => $row['Regular'], $levels)),
    ];
}

test('the cart snapshot records each level row with Regular flagged as the default', function () {
    ['f' => $f, 'levels' => $levels] = deviationFixture();
    $r = $f['restaurant'];

    // Defaults for size and topping; Regular mozzarella and sauce; Double basil.
    $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id, $levels['Mozzarella']['Regular'], $levels['Tomato sauce']['Regular'], $levels['Basil']['Double']],
    ])->assertRedirect();

    $line = CartItem::sole();
    $snapshot = $line->modifiers;

    expect($snapshot['version'])->toBe(2)
        ->and($line->unit_price_cents)->toBe(1400 + 150);

    $byName = collect($snapshot['groups'])->keyBy('group_name');
    expect($byName->keys()->all())->toBe(['Size', 'Toppings', 'Mozzarella', 'Basil', 'Tomato sauce'])
        ->and($byName['Basil']['kind'])->toBe('ingredient')
        ->and($byName['Basil']['single_select'])->toBeTrue()
        ->and(collect($byName['Basil']['selections'])->pluck('option_name')->all())->toBe(['Double'])
        ->and($byName['Basil']['selections'][0]['is_default'])->toBeFalse()
        ->and(collect($byName['Basil']['removed'])->pluck('option_name')->all())->toBe(['Regular'])
        ->and($byName['Mozzarella']['selections'][0]['is_default'])->toBeTrue();
});

test('summaries read as deviations: picks, "No X" for a topping turned off, and No / Half / Double per ingredient', function () {
    ['f' => $f, 'levels' => $levels] = deviationFixture();
    $r = $f['restaurant'];

    // Small instead of medium (pick-one: no "No Medium"), bacon instead of
    // the default pepperoni (multi: "No Pepperoni" matters), no basil, half
    // sauce, regular mozzarella.
    $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_small']->id, $f['top_bacon']->id, $levels['Mozzarella']['Regular'], $levels['Basil']['None'], $levels['Tomato sauce']['Half']],
    ]);

    $line = CartItem::sole();
    $data = CartItemData::fromModel($line);

    expect($data->selectionSummary)->toBe('Small · Bacon · No Pepperoni · No Basil · Half Tomato sauce')
        ->and($data->selectedOptionIds)->toBe([$f['size_small']->id, $f['top_bacon']->id, $levels['Mozzarella']['Regular'], $levels['Basil']['None'], $levels['Tomato sauce']['Half']])
        ->and(collect($data->selectionGroups)->pluck('groupName')->all())->toBe(['Size', 'Toppings', 'Basil', 'Tomato sauce'])
        ->and(collect($data->selectionGroups)->firstWhere('groupName', 'Basil')['selectionNames'])->toBe(['No Basil']);

    // Order lines carry the same snapshot and render the same way.
    $orderItem = new OrderItem(['name' => 'Pep', 'quantity' => 1, 'unit_price_cents' => 1400, 'subtotal_cents' => 1400, 'modifiers' => $line->modifiers, 'notes' => null]);
    $orderItem->id = 1;
    expect(OrderItemData::fromModel($orderItem)->modifierSummary)->toBe('Small · Bacon · No Pepperoni · No Basil · Half Tomato sauce');
});

test('leaving every ingredient at Regular renders no noise', function () {
    ['f' => $f, 'regular' => $regular] = deviationFixture();
    $r = $f['restaurant'];

    $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id, ...$regular],
    ]);

    expect(CartItemData::fromModel(CartItem::sole())->selectionSummary)->toBe('Medium · Pepperoni');
});

test('legacy snapshots without kinds render every selection as before', function () {
    $legacy = [
        'template_id' => 1,
        'template_name' => 'Pizza',
        'groups' => [
            ['group_id' => 1, 'group_name' => 'Size', 'selections' => [['option_id' => 1, 'option_name' => 'Medium', 'price_delta_cents' => 0]]],
            ['group_id' => 2, 'group_name' => 'Toppings', 'selections' => [['option_id' => 5, 'option_name' => 'Pepperoni', 'price_delta_cents' => 200]]],
        ],
    ];

    expect(ModifierSummary::summary($legacy))->toBe('Medium · Pepperoni')
        ->and(ModifierSummary::selectedOptionIds($legacy))->toBe([1, 5])
        ->and(ModifierSummary::groups(null))->toBe([]);
});

test('checkout re-prices a line that left out a priced default', function () {
    ['f' => $f, 'regular' => $regular] = deviationFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, ...$regular],
    ]);
    $cookie = cartCookieFrom($first);

    // Pepperoni (+200) is a default the customer left out: the line is 1200.
    expect(CartItem::sole()->unit_price_cents)->toBe(1200);

    // Now the owner makes pepperoni free; the stored price is stale and
    // the integrity check must catch it even though no *extra* option was
    // selected (the old code skipped lines with no non-default selections).
    $f['top_pepperoni']->update(['price_delta_cents' => 0]);

    $resp = $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ], ['Accept' => 'application/json']);

    expect($resp->status())->toBe(422)
        ->and(json_encode($resp->json()))->toContain('price');
});
