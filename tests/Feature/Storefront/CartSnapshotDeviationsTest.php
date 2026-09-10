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
 * The cart fixture's pizza plus three removable ingredients, one with an
 * extra price, compiled into groups. Returns the fixture plus the compiled
 * option ids by name.
 *
 * @return array{f: array<string, mixed>, included: array<string, int>, extras: array<string, int>}
 */
function deviationFixture(): array
{
    $f = cartFixture();
    foreach ([['Mozzarella', null], ['Basil', 150], ['Tomato sauce', null]] as $i => [$name, $extra]) {
        MenuItemIngredient::create(['menu_item_id' => $f['item']->id, 'name' => $name, 'position' => $i, 'is_removable' => true, 'extra_price_cents' => $extra]);
    }
    app(IngredientGroupCompiler::class)->compile($f['item']->fresh());

    $groups = $f['item']->fresh()->optionGroups();

    return [
        'f' => $f,
        'included' => $groups->firstWhere('kind', 'included')->options->pluck('id', 'name')->map(fn ($id) => (int) $id)->all(),
        'extras' => $groups->firstWhere('kind', 'extras')->options->pluck('id', 'name')->map(fn ($id) => (int) $id)->all(),
    ];
}

test('the cart snapshot records removed defaults and flags default selections', function () {
    ['f' => $f, 'included' => $included, 'extras' => $extras] = deviationFixture();
    $r = $f['restaurant'];

    // Medium + pepperoni (the defaults), keep mozzarella and sauce, leave out basil, add extra basil.
    $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id, $included['Mozzarella'], $included['Tomato sauce'], $extras['Extra Basil']],
    ])->assertRedirect();

    $line = CartItem::sole();
    $snapshot = $line->modifiers;

    expect($snapshot['version'])->toBe(2)
        ->and($line->unit_price_cents)->toBe(1400 + 150);

    $byKind = collect($snapshot['groups'])->keyBy('kind');
    expect($byKind->keys()->all())->toBe(['choice', 'included', 'extras']);

    $includedGroup = $byKind['included'];
    expect(collect($includedGroup['selections'])->pluck('option_name')->all())->toBe(['Mozzarella', 'Tomato sauce'])
        ->and(collect($includedGroup['selections'])->pluck('is_default')->unique()->all())->toBe([true])
        ->and(collect($includedGroup['removed'])->pluck('option_name')->all())->toBe(['Basil']);

    expect(collect($byKind['extras']['selections'])->pluck('option_name')->all())->toBe(['Extra Basil'])
        ->and($byKind['extras']['selections'][0]['is_default'])->toBeFalse()
        ->and($byKind['extras']['removed'])->toBe([]);
});

test('summaries show deviations only: picks, extras, and "No X" for defaults turned off, never the included ingredients', function () {
    ['f' => $f, 'included' => $included, 'extras' => $extras] = deviationFixture();
    $r = $f['restaurant'];

    // Small instead of the default medium (pick-one: no "No Medium"), bacon
    // instead of the default pepperoni (multi: "No Pepperoni" matters to the
    // kitchen), basil and sauce left out, extra basil added.
    $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_small']->id, $f['top_bacon']->id, $included['Mozzarella'], $extras['Extra Basil']],
    ]);

    $line = CartItem::sole();
    $data = CartItemData::fromModel($line);

    expect($data->selectionSummary)->toBe('Small · Bacon · No Pepperoni · No Basil · No Tomato sauce · Extra Basil')
        ->and($data->selectedOptionIds)->toBe([$f['size_small']->id, $f['top_bacon']->id, $included['Mozzarella'], $extras['Extra Basil']])
        ->and(collect($data->selectionGroups)->pluck('groupName')->all())->toBe(['Size', 'Toppings', 'Included', 'Extras'])
        ->and(collect($data->selectionGroups)->firstWhere('groupName', 'Included')['selectionNames'])->toBe(['No Basil', 'No Tomato sauce']);

    // Order lines carry the same snapshot and render the same way.
    $orderItem = new OrderItem(['name' => 'Pep', 'quantity' => 1, 'unit_price_cents' => 1400, 'subtotal_cents' => 1400, 'modifiers' => $line->modifiers, 'notes' => null]);
    $orderItem->id = 1;
    expect(OrderItemData::fromModel($orderItem)->modifierSummary)->toBe('Small · Bacon · No Pepperoni · No Basil · No Tomato sauce · Extra Basil');
});

test('leaving every default on renders no included noise', function () {
    ['f' => $f, 'included' => $included] = deviationFixture();
    $r = $f['restaurant'];

    $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id, ...array_values($included)],
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
    ['f' => $f, 'included' => $included] = deviationFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $included['Mozzarella'], $included['Basil'], $included['Tomato sauce']],
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
