<?php

use App\Enums\MenuImportStatus;
use App\Enums\RestaurantRole;
use App\Jobs\ExtractMenuJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ItemTemplate;
use App\Models\MenuImport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\MenuExtractionService;
use App\Services\RestaurantImageService;
use App\Support\Menus\ExtractedMenuSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const MI_ADMIN_HOST = 'http://admin.plateful.test';

/**
 * @return array{0: User, 1: Restaurant}
 */
function menuImportOwnerAndRestaurant(): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->approved()->create([
        'is_active' => true,
        'subdomain' => 'pizzajoint',
    ]);
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

/**
 * @return array<string, mixed>
 */
function extractionResult(): array
{
    return [
        'categories' => [
            [
                'name' => 'Tacos',
                'items' => [
                    ['name' => 'Carne Asada Taco', 'description' => 'Grilled steak.', 'price_cents' => 399, 'price_note' => null, 'option_set' => 'Taco add-ons'],
                    ['name' => 'Fish Taco', 'description' => null, 'price_cents' => 499, 'price_note' => 'S $4.99 / L $6.99 — imported small', 'option_set' => 'A set that was not extracted'],
                ],
            ],
        ],
        'option_sets' => [
            [
                'name' => 'Taco add-ons',
                'groups' => [
                    [
                        'name' => 'Add-ons',
                        'min_selections' => 0,
                        'max_selections' => null,
                        'options' => [
                            ['name' => 'Guacamole', 'price_delta_cents' => 150, 'is_default' => false],
                            ['name' => 'Salsa', 'price_delta_cents' => 50, 'is_default' => false],
                        ],
                    ],
                ],
            ],
        ],
        'warnings' => ['The drinks section was blurry.'],
        'model' => 'claude-opus-4-8',
        'input_tokens' => 4000,
        'output_tokens' => 800,
    ];
}

it('accepts menu photos, stores them as webp, and queues extraction', function () {
    Queue::fake();
    Storage::fake(RestaurantImageService::disk());
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import", [
            'files' => [
                UploadedFile::fake()->image('menu-page-1.jpg', 1200, 1600),
                UploadedFile::fake()->image('menu-page-2.png', 1200, 1600),
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $import = MenuImport::sole();
    expect($import->status)->toBe(MenuImportStatus::Queued)
        ->and($import->file_paths)->toHaveCount(2)
        ->and($import->file_paths[0])->toEndWith('.webp');

    Storage::disk(RestaurantImageService::disk())->assertExists($import->file_paths[0]);
    Queue::assertPushed(ExtractMenuJob::class);
});

it('queues an import even when the menu already has items (re-import)', function () {
    Queue::fake();
    Storage::fake(RestaurantImageService::disk());
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $restaurant->menuCategories()->create(['name' => 'Pizza', 'slug' => 'pizza', 'position' => 0, 'is_active' => true])
        ->items()->create(['restaurant_id' => $restaurant->id, 'name' => 'Plain', 'slug' => 'plain', 'price_cents' => 1000, 'is_available' => true, 'position' => 0]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import", [
            'files' => [UploadedFile::fake()->image('menu.jpg')],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Queue::assertPushed(ExtractMenuJob::class);
});

it('rejects a new import while one is in flight', function () {
    Queue::fake();
    Storage::fake(RestaurantImageService::disk());
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    MenuImport::factory()->processing()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import", [
            'files' => [UploadedFile::fake()->image('menu.jpg')],
        ])
        ->assertSessionHasErrors('files');
});

it('replaces a previous failed import when retrying', function () {
    Queue::fake();
    Storage::fake(RestaurantImageService::disk());
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $failed = MenuImport::factory()->failed()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import", [
            'files' => [UploadedFile::fake()->image('menu.jpg')],
        ])
        ->assertSessionHasNoErrors();

    expect(MenuImport::whereKey($failed->id)->exists())->toBeFalse()
        ->and(MenuImport::count())->toBe(1);
});

it('blocks staff from starting an import', function () {
    [, $restaurant] = menuImportOwnerAndRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import", [
            'files' => [UploadedFile::fake()->image('menu.jpg')],
        ])
        ->assertForbidden();
});

it('extracts, sanitizes, and marks the import ready for review', function () {
    Storage::fake(RestaurantImageService::disk());
    [, $restaurant] = menuImportOwnerAndRestaurant();

    Storage::disk(RestaurantImageService::disk())->put('menu-imports/test/page-1.webp', 'binary');
    $import = MenuImport::factory()->create([
        'restaurant_id' => $restaurant->id,
        'file_paths' => ['menu-imports/test/page-1.webp'],
    ]);

    $this->mock(MenuExtractionService::class)
        ->shouldReceive('extract')
        ->once()
        ->andReturn(extractionResult());

    (new ExtractMenuJob($import))->handle(app(MenuExtractionService::class));

    $import->refresh();
    expect($import->status)->toBe(MenuImportStatus::NeedsReview)
        ->and($import->itemCount())->toBe(2)
        ->and($import->result['warnings'])->toContain('The drinks section was blurry.')
        ->and($import->input_tokens)->toBe(4000);
});

it('sanitizes option sets and drops dangling item references', function () {
    Storage::fake(RestaurantImageService::disk());
    [, $restaurant] = menuImportOwnerAndRestaurant();

    Storage::disk(RestaurantImageService::disk())->put('menu-imports/test/page-1.webp', 'binary');
    $import = MenuImport::factory()->create([
        'restaurant_id' => $restaurant->id,
        'file_paths' => ['menu-imports/test/page-1.webp'],
    ]);

    $this->mock(MenuExtractionService::class)
        ->shouldReceive('extract')
        ->once()
        ->andReturn(extractionResult());

    (new ExtractMenuJob($import))->handle(app(MenuExtractionService::class));

    $import->refresh();
    $items = $import->result['categories'][0]['items'];

    expect($import->result['option_sets'])->toHaveCount(1)
        ->and($import->result['option_sets'][0]['name'])->toBe('Taco add-ons')
        ->and($items[0]['option_set'])->toBe('Taco add-ons')
        ->and($items[1]['option_set'])->toBeNull();
});

it('marks the import failed with a friendly message when extraction blows up', function () {
    Storage::fake(RestaurantImageService::disk());
    [, $restaurant] = menuImportOwnerAndRestaurant();

    Storage::disk(RestaurantImageService::disk())->put('menu-imports/test/page-1.webp', 'binary');
    $import = MenuImport::factory()->create([
        'restaurant_id' => $restaurant->id,
        'file_paths' => ['menu-imports/test/page-1.webp'],
    ]);

    $this->mock(MenuExtractionService::class)
        ->shouldReceive('extract')
        ->andThrow(new RuntimeException('No menu items could be read from those files.'));

    (new ExtractMenuJob($import))->handle(app(MenuExtractionService::class));

    $import->refresh();
    expect($import->status)->toBe(MenuImportStatus::Failed)
        ->and($import->error)->toContain('couldn’t read any menu items');
});

it('renders the review page for the restaurant admin', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->get(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/review")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/MenuImportReview')
            ->where('menuImport.id', $import->id)
            ->where('menuImport.itemCount', 2)
            ->has('menuImport.categories', 1));
});

it('refuses the review page for an import belonging to another restaurant', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $other = Restaurant::factory()->create();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $other->id]);

    $this->actingAs($owner)
        ->get(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/review")
        ->assertNotFound();
});

it('imports the confirmed draft into the menu with uniquified slugs', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/confirm", [
            'categories' => [
                [
                    'name' => 'Tacos',
                    'items' => [
                        ['name' => 'Coke', 'description' => null, 'price_cents' => 299],
                        ['name' => 'Carne Asada', 'description' => 'Steak.', 'price_cents' => 1299],
                    ],
                ],
                [
                    'name' => 'Drinks',
                    'items' => [
                        ['name' => 'Coke', 'description' => null, 'price_cents' => 299],
                    ],
                ],
            ],
        ])
        ->assertRedirect(MI_ADMIN_HOST."/{$restaurant->subdomain}/onboarding");

    expect($restaurant->menuItems()->count())->toBe(3)
        ->and($restaurant->menuCategories()->count())->toBe(2)
        ->and($restaurant->menuItems()->pluck('slug')->sort()->values()->all())
        ->toBe(['carne-asada', 'coke', 'coke-2'])
        ->and($import->fresh()->status)->toBe(MenuImportStatus::Completed);
});

it('imports option sets as item templates with defaults synced onto items', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/confirm", [
            'categories' => [
                [
                    'name' => 'Coffee',
                    'items' => [
                        ['name' => 'Latte', 'description' => null, 'price_cents' => 450, 'option_set' => 'Espresso drink options'],
                        ['name' => 'Biscotti', 'description' => null, 'price_cents' => 300, 'option_set' => null],
                    ],
                ],
            ],
            'option_sets' => [
                [
                    'name' => 'Espresso drink options',
                    'groups' => [
                        [
                            'name' => 'Milk',
                            'min_selections' => 1,
                            'max_selections' => 1,
                            'options' => [
                                ['name' => 'Whole milk', 'price_delta_cents' => 0, 'is_default' => true],
                                ['name' => 'Oat milk', 'price_delta_cents' => 150, 'is_default' => false],
                            ],
                        ],
                        [
                            'name' => 'Syrups',
                            'min_selections' => 0,
                            'max_selections' => null,
                            'options' => [
                                ['name' => 'Vanilla', 'price_delta_cents' => 50, 'is_default' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->assertRedirect(MI_ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertSessionHasNoErrors();

    $template = ItemTemplate::withoutTenantScope()->where('restaurant_id', $restaurant->id)->sole();
    expect($template->name)->toBe('Espresso drink options')
        ->and($template->groups)->toHaveCount(2)
        ->and($template->groups[0]->options)->toHaveCount(2);

    $latte = $restaurant->menuItems()->where('name', 'Latte')->sole();
    $biscotti = $restaurant->menuItems()->where('name', 'Biscotti')->sole();
    $wholeMilk = $template->groups[0]->options->firstWhere('name', 'Whole milk');

    expect($latte->item_template_id)->toBe($template->id)
        ->and($latte->defaultSelections()->pluck('item_template_options.id')->all())->toBe([$wholeMilk->id])
        ->and($biscotti->item_template_id)->toBeNull();
});

it('replaces the existing menu on confirm, keeping order history and templates', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $category = $restaurant->menuCategories()->create(['name' => 'Old', 'slug' => 'old', 'position' => 0, 'is_active' => true]);
    $oldItem = $category->items()->create(['restaurant_id' => $restaurant->id, 'name' => 'Old Burger', 'slug' => 'old-burger', 'price_cents' => 1000, 'is_available' => true, 'position' => 0]);
    $handBuiltTemplate = ItemTemplate::create(['restaurant_id' => $restaurant->id, 'name' => 'Hand-built', 'is_active' => true, 'position' => 0]);

    $cart = Cart::create(['restaurant_id' => $restaurant->id, 'token' => 'tok', 'expires_at' => now()->addDay()]);
    $cart->items()->create(['menu_item_id' => $oldItem->id, 'quantity' => 1, 'unit_price_cents' => 1000, 'selection_signature' => 'sig']);

    $order = Order::factory()->create(['restaurant_id' => $restaurant->id]);
    $orderItem = OrderItem::create([
        'order_id' => $order->id, 'menu_item_id' => $oldItem->id, 'name' => 'Old Burger',
        'unit_price_cents' => 1000, 'quantity' => 1, 'modifiers' => null, 'subtotal_cents' => 1000,
    ]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/confirm", [
            'categories' => [
                ['name' => 'New', 'items' => [['name' => 'New Burger', 'description' => null, 'price_cents' => 1200, 'option_set' => null]]],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect($restaurant->menuItems()->pluck('name')->all())->toBe(['New Burger'])
        ->and($restaurant->menuCategories()->pluck('name')->all())->toBe(['New'])
        ->and(CartItem::count())->toBe(0)
        ->and($orderItem->fresh()->menu_item_id)->toBeNull()
        ->and($orderItem->fresh()->name)->toBe('Old Burger')
        ->and(ItemTemplate::withoutTenantScope()->whereKey($handBuiltTemplate->id)->exists())->toBeTrue();
});

it('redirects import flows to the menu page once onboarding is complete', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $restaurant->update(['onboarding_completed_at' => now()]);
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/confirm", [
            'categories' => [
                ['name' => 'New', 'items' => [['name' => 'Thing', 'description' => null, 'price_cents' => 500, 'option_set' => null]]],
            ],
        ])
        ->assertRedirect(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu");
});

it('exposes replace context and back url on the review page', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $restaurant->update(['onboarding_completed_at' => now()]);
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);
    $restaurant->menuCategories()->create(['name' => 'Old', 'slug' => 'old', 'position' => 0, 'is_active' => true])
        ->items()->create(['restaurant_id' => $restaurant->id, 'name' => 'Plain', 'slug' => 'plain', 'price_cents' => 1000, 'is_available' => true, 'position' => 0]);

    $this->actingAs($owner)
        ->get(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/review")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('existingItemCount', 1)
            ->where('backUrl', MI_ADMIN_HOST."/{$restaurant->subdomain}/menu"));
});

it('exposes the active import on the menu page for polling', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    MenuImport::factory()->processing()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->get(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/Menu')
            ->where('menuImport.status', 'processing')
            ->has('menuImportLimits.maxFiles'));
});

it('refuses to confirm an item referencing an unknown option set', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/confirm", [
            'categories' => [
                ['name' => 'Coffee', 'items' => [['name' => 'Latte', 'description' => null, 'price_cents' => 450, 'option_set' => 'Ghost set']]],
            ],
            'option_sets' => [],
        ])
        ->assertSessionHasErrors('categories.0.items.0.option_set');

    expect($restaurant->menuItems()->count())->toBe(0);
});

it('refuses to confirm items without a price', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/confirm", [
            'categories' => [
                ['name' => 'Tacos', 'items' => [['name' => 'Mystery Taco', 'description' => null, 'price_cents' => 0]]],
            ],
        ])
        ->assertSessionHasErrors('categories.0.items.0.price_cents');

    expect($restaurant->menuItems()->count())->toBe(0);
});

it('discards an import and deletes its files', function () {
    Storage::fake(RestaurantImageService::disk());
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();

    Storage::disk(RestaurantImageService::disk())->put('menu-imports/batch/page-1.webp', 'binary');
    $import = MenuImport::factory()->needsReview()->create([
        'restaurant_id' => $restaurant->id,
        'file_paths' => ['menu-imports/batch/page-1.webp'],
    ]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/discard")
        ->assertRedirect();

    expect(MenuImport::whereKey($import->id)->exists())->toBeFalse();
    Storage::disk(RestaurantImageService::disk())->assertMissing('menu-imports/batch/page-1.webp');
});

it('exposes the active import on the onboarding wizard for polling', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    MenuImport::factory()->processing()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->get(MI_ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertInertia(fn ($page) => $page
            ->where('menuImport.status', 'processing')
            ->has('menuImportLimits.maxFiles'));
});

it('sanitizer zeroes absurd prices and flags them for review', function () {
    $result = ExtractedMenuSanitizer::sanitize([
        [
            'name' => 'Mains',
            'items' => [
                ['name' => 'Steak', 'description' => null, 'price_cents' => 12999999, 'price_note' => null],
                ['name' => 'Fries', 'description' => 'Crispy.', 'price_cents' => 499, 'price_note' => null],
            ],
        ],
    ]);

    expect($result['categories'][0]['items'][0]['price_cents'])->toBe(0)
        ->and($result['categories'][0]['items'][0]['price_note'])->toContain('looked wrong')
        ->and($result['categories'][0]['items'][1]['price_cents'])->toBe(499);
});

it('sanitizer throws when nothing readable was extracted', function () {
    ExtractedMenuSanitizer::sanitize([
        ['name' => 'Empty', 'items' => []],
    ]);
})->throws(RuntimeException::class);

it('sanitizer repairs group defaults to satisfy min and max selections', function () {
    $result = ExtractedMenuSanitizer::sanitize(
        [['name' => 'Coffee', 'items' => [['name' => 'Latte', 'description' => null, 'price_cents' => 450, 'price_note' => null, 'option_set' => 'Drinks']]]],
        [],
        [[
            'name' => 'Drinks',
            'groups' => [
                [
                    // Required single-select without a default: first becomes default.
                    'name' => 'Size',
                    'min_selections' => 1,
                    'max_selections' => 1,
                    'options' => [
                        ['name' => 'Small', 'price_delta_cents' => 0, 'is_default' => false],
                        ['name' => 'Large', 'price_delta_cents' => 100, 'is_default' => true],
                    ],
                ],
            ],
        ]],
    );

    $options = $result['option_sets'][0]['groups'][0]['options'];
    expect(array_column($options, 'is_default'))->toBe([false, true]);

    $repaired = ExtractedMenuSanitizer::sanitize(
        [['name' => 'Coffee', 'items' => [['name' => 'Latte', 'description' => null, 'price_cents' => 450, 'price_note' => null, 'option_set' => 'Drinks']]]],
        [],
        [[
            'name' => 'Drinks',
            'groups' => [
                [
                    'name' => 'Size',
                    'min_selections' => 1,
                    'max_selections' => 1,
                    'options' => [
                        ['name' => 'Small', 'price_delta_cents' => 0, 'is_default' => false],
                        ['name' => 'Large', 'price_delta_cents' => 100, 'is_default' => false],
                    ],
                ],
            ],
        ]],
    );

    expect(array_column($repaired['option_sets'][0]['groups'][0]['options'], 'is_default'))->toBe([true, false]);
});

it('sanitizer resets absurd option price deltas and warns', function () {
    $result = ExtractedMenuSanitizer::sanitize(
        [['name' => 'Coffee', 'items' => [['name' => 'Latte', 'description' => null, 'price_cents' => 450, 'price_note' => null, 'option_set' => null]]]],
        [],
        [[
            'name' => 'Drinks',
            'groups' => [[
                'name' => 'Milk',
                'min_selections' => 0,
                'max_selections' => 1,
                'options' => [['name' => 'Oat milk', 'price_delta_cents' => 99999999, 'is_default' => false]],
            ]],
        ]],
    );

    expect($result['option_sets'][0]['groups'][0]['options'][0]['price_delta_cents'])->toBe(0)
        ->and(implode(' ', $result['warnings']))->toContain('Oat milk');
});

it('sanitizer drops empty groups and sets, clearing item references to them', function () {
    $result = ExtractedMenuSanitizer::sanitize(
        [['name' => 'Coffee', 'items' => [['name' => 'Latte', 'description' => null, 'price_cents' => 450, 'price_note' => null, 'option_set' => 'Drinks']]]],
        [],
        [['name' => 'Drinks', 'groups' => [['name' => 'Milk', 'min_selections' => 0, 'max_selections' => null, 'options' => []]]]],
    );

    expect($result['option_sets'])->toBe([])
        ->and($result['categories'][0]['items'][0]['option_set'])->toBeNull();
});

it('exposes option sets on the review page', function () {
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $import = MenuImport::factory()->needsReview()->create(['restaurant_id' => $restaurant->id]);
    $result = $import->result;
    $result['option_sets'] = [[
        'name' => 'Taco add-ons',
        'groups' => [[
            'name' => 'Add-ons',
            'min_selections' => 0,
            'max_selections' => null,
            'options' => [['name' => 'Guacamole', 'price_delta_cents' => 150, 'is_default' => false]],
        ]],
    ]];
    $import->update(['result' => $result]);

    $this->actingAs($owner)
        ->get(MI_ADMIN_HOST."/{$restaurant->subdomain}/menu-import/{$import->id}/review")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/MenuImportReview')
            ->has('menuImport.optionSets', 1)
            ->where('menuImport.optionSets.0.name', 'Taco add-ons'));
});
