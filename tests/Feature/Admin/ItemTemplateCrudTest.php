<?php

use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;

const TPL_ADMIN_BASE = 'http://admin.plateful.test';

function tplRestaurant(string $sub): Restaurant
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

function tplAttachAdmin(Restaurant $r): User
{
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($r->id);

    return $admin;
}

test('admin can create a template with groups and options in one POST', function () {
    $r = tplRestaurant('marcos');
    $admin = tplAttachAdmin($r);

    $this->actingAs($admin)
        ->post(TPL_ADMIN_BASE.'/marcos/menu/templates', [
            'name' => 'Pizza',
            'is_active' => true,
            'groups' => [
                [
                    'name' => 'Size',
                    'min_selections' => 1,
                    'max_selections' => 1,
                    'options' => [
                        ['name' => 'Small', 'price_delta' => '-2.00', 'is_available' => true],
                        ['name' => 'Medium', 'price_delta' => '0.00', 'is_available' => true],
                    ],
                ],
                [
                    'name' => 'Toppings',
                    'min_selections' => 0,
                    'max_selections' => null,
                    'options' => [
                        ['name' => 'Bacon', 'price_delta' => '3.00', 'is_available' => true],
                    ],
                ],
            ],
        ])
        ->assertRedirect();

    $tpl = ItemTemplate::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    expect($tpl)->not->toBeNull()
        ->and($tpl->groups()->count())->toBe(2);

    $size = $tpl->groups()->where('name', 'Size')->first();
    expect($size->options()->count())->toBe(2)
        ->and($size->options()->where('name', 'Small')->first()->price_delta_cents)->toBe(-200);
});

test('cross-tenant template edit returns 404', function () {
    $a = tplRestaurant('marcos');
    $b = tplRestaurant('other');
    $admin = tplAttachAdmin($a);

    $bTemplate = ItemTemplate::withoutTenantScope()->create([
        'restaurant_id' => $b->id,
        'name' => 'Pizza',
        'is_active' => true,
        'position' => 0,
    ]);

    // Admin of A trying to access B's template via A's URL: bound restaurant id won't match.
    $this->actingAs($admin)
        ->get(TPL_ADMIN_BASE."/marcos/menu/templates/{$bTemplate->id}/edit")
        ->assertNotFound();
});

test('template update replaces groups and options', function () {
    $r = tplRestaurant('marcos');
    $admin = tplAttachAdmin($r);

    $tpl = ItemTemplate::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizza',
        'is_active' => true,
        'position' => 0,
    ]);
    $oldGroup = ItemTemplateGroup::create([
        'item_template_id' => $tpl->id,
        'name' => 'OldGroup',
        'min_selections' => 0,
        'max_selections' => null,
        'position' => 0,
    ]);
    $oldOption = ItemTemplateOption::create([
        'item_template_group_id' => $oldGroup->id,
        'name' => 'OldOpt',
        'price_delta_cents' => 0,
        'is_available' => true,
        'position' => 0,
    ]);

    $this->actingAs($admin)
        ->put(TPL_ADMIN_BASE."/marcos/menu/templates/{$tpl->id}", [
            'name' => 'Pizza',
            'is_active' => true,
            'groups' => [
                [
                    'id' => $oldGroup->id,
                    'name' => 'KeptGroup',
                    'min_selections' => 0,
                    'max_selections' => null,
                    'options' => [
                        ['id' => $oldOption->id, 'name' => 'KeptOpt', 'price_delta' => '1.00', 'is_available' => true],
                        ['name' => 'NewOpt', 'price_delta' => '2.00', 'is_available' => true],
                    ],
                ],
                [
                    'name' => 'BrandNew',
                    'min_selections' => 0,
                    'max_selections' => null,
                    'options' => [
                        ['name' => 'X', 'price_delta' => '0.00', 'is_available' => true],
                    ],
                ],
            ],
        ])
        ->assertRedirect();

    $tpl->refresh();
    expect($tpl->groups()->count())->toBe(2)
        ->and(ItemTemplateGroup::find($oldGroup->id)->name)->toBe('KeptGroup')
        ->and(ItemTemplateOption::find($oldOption->id)->name)->toBe('KeptOpt')
        ->and(ItemTemplateOption::find($oldOption->id)->price_delta_cents)->toBe(100);
});

test('cannot delete a template that has menu items', function () {
    $r = tplRestaurant('marcos');
    $admin = tplAttachAdmin($r);

    $tpl = ItemTemplate::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizza',
        'is_active' => true,
        'position' => 0,
    ]);

    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas',
        'position' => 0,
        'is_active' => true,
    ]);

    MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'item_template_id' => $tpl->id,
        'name' => 'Pep',
        'slug' => 'pep',
        'price_cents' => 1000,
        'is_available' => true,
        'position' => 0,
    ]);

    $this->actingAs($admin)
        ->delete(TPL_ADMIN_BASE."/marcos/menu/templates/{$tpl->id}", [], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(ItemTemplate::withoutTenantScope()->find($tpl->id))->not->toBeNull();
});

test('max_selections less than min_selections fails validation', function () {
    $r = tplRestaurant('marcos');
    $admin = tplAttachAdmin($r);

    $this->actingAs($admin)
        ->post(TPL_ADMIN_BASE.'/marcos/menu/templates', [
            'name' => 'Bad',
            'is_active' => true,
            'groups' => [
                [
                    'name' => 'Size',
                    'min_selections' => 2,
                    'max_selections' => 1,
                    'options' => [
                        ['name' => 'A', 'price_delta' => '0.00', 'is_available' => true],
                    ],
                ],
            ],
        ])
        ->assertSessionHasErrors('groups.0.max_selections');
});

test('empty group name fails validation', function () {
    $r = tplRestaurant('marcos');
    $admin = tplAttachAdmin($r);

    $this->actingAs($admin)
        ->post(TPL_ADMIN_BASE.'/marcos/menu/templates', [
            'name' => 'Pizza',
            'is_active' => true,
            'groups' => [
                [
                    'name' => '',
                    'min_selections' => 0,
                    'max_selections' => null,
                    'options' => [],
                ],
            ],
        ])
        ->assertSessionHasErrors('groups.0.name');
});
