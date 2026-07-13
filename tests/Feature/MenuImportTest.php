<?php

use App\Enums\MenuImportStatus;
use App\Enums\RestaurantRole;
use App\Jobs\ExtractMenuJob;
use App\Models\MenuImport;
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
                    ['name' => 'Carne Asada Taco', 'description' => 'Grilled steak.', 'price_cents' => 399, 'price_note' => null],
                    ['name' => 'Fish Taco', 'description' => null, 'price_cents' => 499, 'price_note' => 'S $4.99 / L $6.99 — imported large'],
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
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/onboarding/menu-import", [
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

it('rejects an import when the menu already has items', function () {
    Queue::fake();
    Storage::fake(RestaurantImageService::disk());
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    $restaurant->menuCategories()->create(['name' => 'Pizza', 'slug' => 'pizza', 'position' => 0, 'is_active' => true])
        ->items()->create(['restaurant_id' => $restaurant->id, 'name' => 'Plain', 'slug' => 'plain', 'price_cents' => 1000, 'is_available' => true, 'position' => 0]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/onboarding/menu-import", [
            'files' => [UploadedFile::fake()->image('menu.jpg')],
        ])
        ->assertSessionHasErrors('files');

    Queue::assertNothingPushed();
});

it('rejects a new import while one is in flight', function () {
    Queue::fake();
    Storage::fake(RestaurantImageService::disk());
    [$owner, $restaurant] = menuImportOwnerAndRestaurant();
    MenuImport::factory()->processing()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/onboarding/menu-import", [
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
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/onboarding/menu-import", [
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
        ->post(MI_ADMIN_HOST."/{$restaurant->subdomain}/onboarding/menu-import", [
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
