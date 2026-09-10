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

test('compiles removable ingredients into an included group of default-on options and priced extras', function () {
    $f = compilerFixture();

    app(IngredientGroupCompiler::class)->compile($f['item']);

    $item = $f['item']->fresh();
    $groups = $item->optionGroups();

    expect($groups->pluck('kind')->all())->toBe(['choice', 'included', 'extras']);

    $included = $groups->firstWhere('kind', 'included');
    expect($included->menu_item_id)->toBe($item->id)
        ->and($included->item_template_id)->toBeNull()
        ->and($included->min_selections)->toBe(0)
        ->and($included->max_selections)->toBeNull()
        // The non-removable dressing is not offered.
        ->and($included->options->pluck('name')->all())->toBe(['Cotto salami', 'Mortadella', 'Provolone', 'Lettuce', 'Pepperoncini', 'Mayo'])
        ->and($included->options->pluck('price_delta_cents')->unique()->all())->toBe([0]);

    $extras = $groups->firstWhere('kind', 'extras');
    expect($extras->options->pluck('name')->all())->toBe(['Extra Mortadella', 'Extra Provolone'])
        ->and($extras->options->pluck('price_delta_cents')->all())->toBe([150, 100]);

    // Every included option is a default; the hand-set size default survives.
    $defaults = $item->defaultSelections()->pluck('item_template_options.id')->all();
    expect($defaults)->toContain($f['small']->id);
    foreach ($included->options as $option) {
        expect($defaults)->toContain($option->id);
    }
    foreach ($extras->options as $option) {
        expect($defaults)->not->toContain($option->id);
    }
});

test('recompiling keeps generated option ids stable and drops options for removed ingredients', function () {
    $f = compilerFixture();
    $compiler = app(IngredientGroupCompiler::class);
    $compiler->compile($f['item']);

    $before = $f['item']->fresh()->optionGroups()->firstWhere('kind', 'included')->options->keyBy('name');
    $mortadellaId = $before['Mortadella']->id;

    MenuItemIngredient::where('menu_item_id', $f['item']->id)->where('name', 'Mortadella')->update(['extra_price_cents' => 200]);
    MenuItemIngredient::where('menu_item_id', $f['item']->id)->where('name', 'Mayo')->delete();

    $compiler->compile($f['item']->fresh());

    $after = $f['item']->fresh()->optionGroups();
    $included = $after->firstWhere('kind', 'included');
    expect($included->options->keyBy('name')['Mortadella']->id)->toBe($mortadellaId)
        ->and($included->options->pluck('name')->all())->not->toContain('Mayo')
        ->and($after->firstWhere('kind', 'extras')->options->firstWhere('name', 'Extra Mortadella')->price_delta_cents)->toBe(200)
        ->and(ItemTemplateGroup::where('menu_item_id', $f['item']->id)->count())->toBe(2);
});

test('an item whose ingredients allow nothing gets no compiled groups', function () {
    $f = compilerFixture();
    MenuItemIngredient::where('menu_item_id', $f['item']->id)->update(['is_removable' => false, 'extra_price_cents' => null]);

    app(IngredientGroupCompiler::class)->compile($f['item']->fresh());

    expect(ItemTemplateGroup::where('menu_item_id', $f['item']->id)->count())->toBe(0)
        ->and($f['item']->fresh()->optionGroups()->pluck('kind')->all())->toBe(['choice']);
});

test('a swappable ingredient attaches its swap set and defaults to the printed ingredient', function () {
    $f = compilerFixture();
    MenuItemIngredient::where('menu_item_id', $f['item']->id)->where('name', 'Provolone')
        ->update(['swap_template_id' => $f['cheeses']->id]);

    app(IngredientGroupCompiler::class)->compile($f['item']->fresh());

    $item = $f['item']->fresh();
    $groups = $item->optionGroups();

    expect($item->templates()->pluck('item_templates.id')->all())->toBe([$f['size']->id, $f['cheeses']->id])
        ->and($groups->firstWhere('name', 'Cheese')->kind)->toBe('swap')
        // Swapped ingredients leave the included checklist.
        ->and($groups->firstWhere('kind', 'included')->options->pluck('name')->all())->not->toContain('Provolone')
        ->and($item->defaultSelections()->pluck('item_template_options.id')->all())->toContain($f['provolone']->id)
        ->and($item->priceForSelectionsCents([$f['small']->id, $f['mozzarella']->id, ...$groups->firstWhere('kind', 'included')->options->pluck('id')->all()]))->toBe(1050 + 75);

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
