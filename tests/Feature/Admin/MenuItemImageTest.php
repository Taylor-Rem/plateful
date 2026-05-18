<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

const ITEM_IMG_ADMIN_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    Storage::fake('restaurant_assets');
});

function itemImageRestaurant(string $sub = 'marcos'): Restaurant
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

function itemImageAdmin(Restaurant $r): User
{
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($r->id);

    return $admin;
}

function itemImageCategory(Restaurant $r): MenuCategory
{
    return MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas',
        'position' => 0,
        'is_active' => true,
    ]);
}

test('admin can create an item with an image and all 3 webp variants are written', function () {
    $r = itemImageRestaurant();
    $admin = itemImageAdmin($r);
    $cat = itemImageCategory($r);

    test()->actingAs($admin)
        ->post(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items", [
            'name' => 'Margherita',
            'menu_category_id' => $cat->id,
            'price' => '13.99',
            'is_available' => true,
            'image' => UploadedFile::fake()->image('pizza.jpg', 1000, 800),
        ])->assertRedirect();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $r->id)->first();

    expect($item->image_path)->not->toBeNull()
        ->and($item->image_path)->toStartWith("restaurants/{$r->id}/menu-items/{$item->id}/")
        ->and($item->image_path)->toEndWith('.webp');

    $disk = Storage::disk('restaurant_assets');
    foreach (app(RestaurantImageService::class)->variantPaths($item->image_path) as $variant) {
        expect($disk->exists($variant))->toBeTrue();
    }
});

test('replacing an item image deletes the previous variants', function () {
    $r = itemImageRestaurant();
    $admin = itemImageAdmin($r);
    $cat = itemImageCategory($r);

    test()->actingAs($admin)
        ->post(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items", [
            'name' => 'First',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'image' => UploadedFile::fake()->image('first.jpg', 600, 600),
        ])->assertRedirect();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    $oldVariants = app(RestaurantImageService::class)->variantPaths($item->image_path);

    test()->actingAs($admin)
        ->put(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items/{$item->id}", [
            'name' => 'First',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'image' => UploadedFile::fake()->image('second.png', 600, 600),
        ])->assertRedirect();

    $disk = Storage::disk('restaurant_assets');
    foreach ($oldVariants as $variant) {
        expect($disk->exists($variant))->toBeFalse();
    }

    $newPath = $item->fresh()->image_path;
    foreach (app(RestaurantImageService::class)->variantPaths($newPath) as $variant) {
        expect($disk->exists($variant))->toBeTrue();
    }
});

test('remove_image clears the column and deletes variants', function () {
    $r = itemImageRestaurant();
    $admin = itemImageAdmin($r);
    $cat = itemImageCategory($r);

    test()->actingAs($admin)
        ->post(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items", [
            'name' => 'Foo',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'image' => UploadedFile::fake()->image('x.jpg', 300, 300),
        ])->assertRedirect();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    $variants = app(RestaurantImageService::class)->variantPaths($item->image_path);

    test()->actingAs($admin)
        ->put(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items/{$item->id}", [
            'name' => 'Foo',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'remove_image' => '1',
        ])->assertRedirect();

    expect($item->fresh()->image_path)->toBeNull();
    $disk = Storage::disk('restaurant_assets');
    foreach ($variants as $v) {
        expect($disk->exists($v))->toBeFalse();
    }
});

test('oversize and wrong-type uploads are rejected for item image', function () {
    $r = itemImageRestaurant();
    $admin = itemImageAdmin($r);
    $cat = itemImageCategory($r);

    test()->actingAs($admin)
        ->post(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items", [
            'name' => 'Big',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'image' => UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg'),
        ])->assertSessionHasErrors('image');

    test()->actingAs($admin)
        ->post(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items", [
            'name' => 'Wrong',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'image' => UploadedFile::fake()->create('notes.txt', 50, 'text/plain'),
        ])->assertSessionHasErrors('image');
});

test('png upload is converted to webp', function () {
    $r = itemImageRestaurant();
    $admin = itemImageAdmin($r);
    $cat = itemImageCategory($r);

    test()->actingAs($admin)
        ->post(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items", [
            'name' => 'PNG',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'image' => UploadedFile::fake()->image('foo.png', 500, 500),
        ])->assertRedirect();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    expect($item->image_path)->toEndWith('.webp');
});

test('deleting a menu item removes its image directory', function () {
    $r = itemImageRestaurant();
    $admin = itemImageAdmin($r);
    $cat = itemImageCategory($r);

    test()->actingAs($admin)
        ->post(ITEM_IMG_ADMIN_BASE."/{$r->subdomain}/menu/items", [
            'name' => 'Toss',
            'menu_category_id' => $cat->id,
            'price' => '10.00',
            'is_available' => true,
            'image' => UploadedFile::fake()->image('toss.jpg', 200, 200),
        ])->assertRedirect();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    $dir = "restaurants/{$r->id}/menu-items/{$item->id}";
    $disk = Storage::disk('restaurant_assets');
    expect($disk->directoryExists($dir))->toBeTrue();

    $item->delete();

    expect($disk->directoryExists($dir))->toBeFalse();
});
