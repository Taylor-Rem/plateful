<?php

use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Restaurant;
use App\Support\Menus\IngredientGroupCompiler;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A Classic Italian sandwich with a shared size template, seven ingredients,
 * and (optionally) a Cheeses swap set.
 *
 * @return array{restaurant: Restaurant, item: MenuItem, size: ItemTemplate, small: ItemTemplateOption, large: ItemTemplateOption, cheeses: ItemTemplate, provolone: ItemTemplateOption, mozzarella: ItemTemplateOption}
 */
function compilerFixture(): array
{
    $r = Restaurant::create([
        'name' => 'Testaurant', 'subdomain' => 'testaurant', 'email' => 't@t.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
    app(CurrentTenant::class)->set($r);

    $cat = MenuCategory::create(['restaurant_id' => $r->id, 'name' => 'Sandwiches', 'slug' => 's', 'position' => 0, 'is_active' => true]);

    $size = ItemTemplate::create(['restaurant_id' => $r->id, 'name' => 'Sandwich size', 'is_active' => true, 'position' => 0]);
    $sizeGroup = ItemTemplateGroup::create(['item_template_id' => $size->id, 'name' => 'Size', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0]);
    $small = ItemTemplateOption::create(['item_template_group_id' => $sizeGroup->id, 'name' => '7"', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 0]);
    $large = ItemTemplateOption::create(['item_template_group_id' => $sizeGroup->id, 'name' => '12"', 'price_delta_cents' => 475, 'is_available' => true, 'position' => 1]);

    $cheeses = ItemTemplate::create(['restaurant_id' => $r->id, 'name' => 'Cheeses', 'is_active' => true, 'position' => 1]);
    $cheeseGroup = ItemTemplateGroup::create(['item_template_id' => $cheeses->id, 'name' => 'Cheese', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0]);
    $provolone = ItemTemplateOption::create(['item_template_group_id' => $cheeseGroup->id, 'name' => 'Provolone', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 0]);
    $mozzarella = ItemTemplateOption::create(['item_template_group_id' => $cheeseGroup->id, 'name' => 'Mozzarella', 'price_delta_cents' => 75, 'is_available' => true, 'position' => 1]);

    $item = MenuItem::create([
        'restaurant_id' => $r->id, 'menu_category_id' => $cat->id, 'name' => 'Classic Italian', 'slug' => 'classic-italian',
        'description' => 'Cotto salami, mortadella, provolone cheese, lettuce, pepperoncini, mayo, and house Italian dressing.',
        'price_cents' => 1050, 'is_available' => true, 'position' => 0,
    ]);
    $item->templates()->attach($size->id, ['position' => 0]);
    $item->defaultSelections()->sync([$small->id]);

    $ingredients = [
        ['Cotto salami', true, null],
        ['Mortadella', true, 150],
        ['Provolone', true, 100],
        ['Lettuce', true, null],
        ['Pepperoncini', true, null],
        ['Mayo', true, null],
        ['House Italian dressing', false, null],
    ];
    foreach ($ingredients as $i => [$name, $removable, $extra]) {
        MenuItemIngredient::create([
            'menu_item_id' => $item->id, 'name' => $name, 'position' => $i,
            'is_removable' => $removable, 'extra_price_cents' => $extra,
        ]);
    }

    return compact('item', 'size', 'small', 'large', 'cheeses', 'provolone', 'mozzarella') + ['restaurant' => $r];
}

test('compiles each ingredient with a rule into a pick-one level row, Regular default, only Double priced', function () {
    $f = compilerFixture();

    app(IngredientGroupCompiler::class)->compile($f['item']);

    $item = $f['item']->fresh();
    $groups = $item->optionGroups();

    // Size template first, then one row per ingredient (all seven have at
    // least Half, since allow_half defaults on).
    expect($groups->pluck('kind')->all())->toBe(['choice', ...array_fill(0, 7, 'ingredient')])
        ->and($groups->where('kind', 'ingredient')->pluck('name')->values()->all())
        ->toBe(['Cotto salami', 'Mortadella', 'Provolone', 'Lettuce', 'Pepperoncini', 'Mayo', 'House Italian dressing']);

    $mortadella = $groups->firstWhere('name', 'Mortadella');
    expect($mortadella->menu_item_id)->toBe($item->id)
        ->and($mortadella->item_template_id)->toBeNull()
        ->and($mortadella->min_selections)->toBe(1)
        ->and($mortadella->max_selections)->toBe(1)
        ->and($mortadella->options->pluck('name')->all())->toBe(['None', 'Half', 'Regular', 'Double'])
        ->and($mortadella->options->pluck('price_delta_cents')->all())->toBe([0, 0, 0, 150]);

    // Not removable, unpriced: Half and Regular only.
    expect($groups->firstWhere('name', 'House Italian dressing')->options->pluck('name')->all())->toBe(['Half', 'Regular']);

    // Regular is the default everywhere; the hand-set size default survives.
    $defaults = $item->defaultSelections()->pluck('item_template_options.id')->all();
    expect($defaults)->toContain($f['small']->id);
    foreach ($groups->where('kind', 'ingredient') as $row) {
        expect($defaults)->toContain($row->options->firstWhere('name', 'Regular')->id);
        foreach ($row->options->where('name', '!=', 'Regular') as $other) {
            expect($defaults)->not->toContain($other->id);
        }
    }
});

test('recompiling keeps generated ids stable and drops rows for removed ingredients', function () {
    $f = compilerFixture();
    $compiler = app(IngredientGroupCompiler::class);
    $compiler->compile($f['item']);

    $before = $f['item']->fresh()->optionGroups()->firstWhere('name', 'Mortadella');
    $groupId = $before->id;
    $regularId = $before->options->firstWhere('name', 'Regular')->id;

    MenuItemIngredient::where('menu_item_id', $f['item']->id)->where('name', 'Mortadella')->update(['extra_price_cents' => 200]);
    MenuItemIngredient::where('menu_item_id', $f['item']->id)->where('name', 'Mayo')->delete();

    $compiler->compile($f['item']->fresh());

    $after = $f['item']->fresh()->optionGroups();
    $mortadella = $after->firstWhere('name', 'Mortadella');
    expect($mortadella->id)->toBe($groupId)
        ->and($mortadella->options->firstWhere('name', 'Regular')->id)->toBe($regularId)
        ->and($mortadella->options->firstWhere('name', 'Double')->price_delta_cents)->toBe(200)
        ->and($after->firstWhere('name', 'Mayo'))->toBeNull()
        ->and(ItemTemplateGroup::where('menu_item_id', $f['item']->id)->count())->toBe(6);
});

test('an ingredient with no rules gets no row, and an item with none gets no compiled groups', function () {
    $f = compilerFixture();
    MenuItemIngredient::where('menu_item_id', $f['item']->id)->update(['is_removable' => false, 'allow_half' => false, 'extra_price_cents' => null]);

    app(IngredientGroupCompiler::class)->compile($f['item']->fresh());

    expect(ItemTemplateGroup::where('menu_item_id', $f['item']->id)->count())->toBe(0)
        ->and($f['item']->fresh()->optionGroups()->pluck('kind')->all())->toBe(['choice']);
});

test('legacy included and extras groups are replaced on the first recompile', function () {
    $f = compilerFixture();
    ItemTemplateGroup::create(['menu_item_id' => $f['item']->id, 'name' => 'Included', 'kind' => 'included', 'min_selections' => 0, 'max_selections' => null, 'position' => 0]);
    ItemTemplateGroup::create(['menu_item_id' => $f['item']->id, 'name' => 'Extras', 'kind' => 'extras', 'min_selections' => 0, 'max_selections' => null, 'position' => 1]);

    app(IngredientGroupCompiler::class)->compile($f['item']->fresh());

    expect(ItemTemplateGroup::where('menu_item_id', $f['item']->id)->pluck('kind')->unique()->all())->toBe(['ingredient']);
});

test('a swappable ingredient attaches its swap set and defaults to the printed ingredient', function () {
    $f = compilerFixture();
    MenuItemIngredient::where('menu_item_id', $f['item']->id)->where('name', 'Provolone')
        ->update(['swap_template_id' => $f['cheeses']->id]);

    app(IngredientGroupCompiler::class)->compile($f['item']->fresh());

    $item = $f['item']->fresh();
    $groups = $item->optionGroups();

    $regulars = $groups->where('kind', 'ingredient')->map(fn ($g) => $g->options->firstWhere('name', 'Regular')->id)->values()->all();

    expect($item->templates()->pluck('item_templates.id')->all())->toBe([$f['size']->id, $f['cheeses']->id])
        ->and($groups->firstWhere('name', 'Cheese')->kind)->toBe('swap')
        // A swappable ingredient keeps its level row as well.
        ->and($groups->firstWhere('name', 'Provolone')->kind)->toBe('ingredient')
        ->and($item->defaultSelections()->pluck('item_template_options.id')->all())->toContain($f['provolone']->id)
        ->and($item->priceForSelectionsCents([$f['small']->id, $f['mozzarella']->id, ...$regulars]))->toBe(1050 + 75);

    // The set is attached through the ingredient, so dropping the swap detaches it.
    MenuItemIngredient::where('menu_item_id', $item->id)->where('name', 'Provolone')->update(['swap_template_id' => null]);
    app(IngredientGroupCompiler::class)->compile($item->fresh());

    expect($item->fresh()->templates()->pluck('item_templates.id')->all())->toBe([$f['size']->id])
        ->and($item->fresh()->defaultSelections()->pluck('item_template_options.id')->all())->not->toContain($f['provolone']->id);
});

test('a swap set the ingredient names an option missing from is completed with a $0 option', function () {
    $f = compilerFixture();
    MenuItemIngredient::where('menu_item_id', $f['item']->id)->where('name', 'Mortadella')
        ->update(['swap_template_id' => $f['cheeses']->id]);

    app(IngredientGroupCompiler::class)->compile($f['item']->fresh());

    $cheeseGroup = $f['cheeses']->fresh()->groups->first();
    $mortadella = $cheeseGroup->options->firstWhere('name', 'Mortadella');
    expect($mortadella)->not->toBeNull()
        ->and($mortadella->price_delta_cents)->toBe(0)
        ->and($f['item']->fresh()->defaultSelections()->pluck('item_template_options.id')->all())->toContain($mortadella->id);
});

test('a template that is not a swap set is rejected', function () {
    $f = compilerFixture();
    $multi = ItemTemplate::create(['restaurant_id' => $f['restaurant']->id, 'name' => 'Toppings', 'is_active' => true, 'position' => 2]);
    ItemTemplateGroup::create(['item_template_id' => $multi->id, 'name' => 'Toppings', 'min_selections' => 0, 'max_selections' => null, 'position' => 0]);
    MenuItemIngredient::where('menu_item_id', $f['item']->id)->where('name', 'Mayo')->update(['swap_template_id' => $multi->id]);

    expect(fn () => app(IngredientGroupCompiler::class)->compile($f['item']->fresh()))
        ->toThrow(InvalidArgumentException::class, 'not a swap set');
});
