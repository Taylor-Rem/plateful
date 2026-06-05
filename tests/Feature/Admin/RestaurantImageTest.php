<?php

use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const SETTINGS_ADMIN_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    Storage::fake(RestaurantImageService::disk());
});

function settingsRestaurant(string $sub = 'marcos'): Restaurant
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

function settingsAdmin(Restaurant $r): User
{
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($r->id);

    return $admin;
}

function postSettings(User $admin, Restaurant $r, array $data): TestResponse
{
    return test()->actingAs($admin)
        ->put(SETTINGS_ADMIN_BASE."/{$r->subdomain}/settings", array_merge([
            'name' => $r->name,
            'description' => $r->description,
            'primary_color' => $r->primary_color,
            'secondary_color' => $r->secondary_color,
            'email' => $r->email,
            'phone' => $r->phone,
        ], $data));
}

test('admin can upload a jpeg logo and all 3 webp variants are written', function () {
    $r = settingsRestaurant();
    $admin = settingsAdmin($r);

    $response = postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->image('logo.jpg', 800, 800),
    ]);

    $response->assertRedirect();

    $r->refresh();
    expect($r->logo_path)->not->toBeNull()
        ->and($r->logo_path)->toEndWith('.webp')
        ->and($r->logo_path)->toStartWith("restaurants/{$r->id}/logo/");

    $disk = Storage::disk(RestaurantImageService::disk());
    $variants = app(RestaurantImageService::class)->variantPaths($r->logo_path);
    foreach ($variants as $variant) {
        expect($disk->exists($variant))->toBeTrue("Expected {$variant} to exist");
        expect($variant)->toEndWith('.webp');
    }
});

test('replacing the logo deletes the previous variants', function () {
    $r = settingsRestaurant();
    $admin = settingsAdmin($r);

    postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->image('first.jpg', 400, 400),
    ])->assertRedirect();

    $oldPath = $r->fresh()->logo_path;
    $oldVariants = app(RestaurantImageService::class)->variantPaths($oldPath);

    postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->image('second.png', 400, 400),
    ])->assertRedirect();

    $disk = Storage::disk(RestaurantImageService::disk());
    foreach ($oldVariants as $variant) {
        expect($disk->exists($variant))->toBeFalse("Old variant {$variant} should be gone");
    }

    $newPath = $r->fresh()->logo_path;
    expect($newPath)->not->toBe($oldPath);

    foreach (app(RestaurantImageService::class)->variantPaths($newPath) as $variant) {
        expect($disk->exists($variant))->toBeTrue();
    }
});

test('remove_logo deletes variants and clears the column', function () {
    $r = settingsRestaurant();
    $admin = settingsAdmin($r);

    postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->image('l.jpg', 300, 300),
    ])->assertRedirect();

    $path = $r->fresh()->logo_path;
    $variants = app(RestaurantImageService::class)->variantPaths($path);

    postSettings($admin, $r, ['remove_logo' => '1'])->assertRedirect();

    expect($r->fresh()->logo_path)->toBeNull();

    $disk = Storage::disk(RestaurantImageService::disk());
    foreach ($variants as $variant) {
        expect($disk->exists($variant))->toBeFalse();
    }
});

test('logo upload over 5 MB is rejected', function () {
    $r = settingsRestaurant();
    $admin = settingsAdmin($r);

    $response = postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg'),
    ]);

    $response->assertSessionHasErrors('logo');
    expect($r->fresh()->logo_path)->toBeNull();
});

test('non-image upload is rejected', function () {
    $r = settingsRestaurant();
    $admin = settingsAdmin($r);

    $response = postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->create('notes.txt', 100, 'text/plain'),
    ]);

    $response->assertSessionHasErrors('logo');
});

test('png upload is accepted and re-encoded to webp', function () {
    $r = settingsRestaurant();
    $admin = settingsAdmin($r);

    postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->image('logo.png', 500, 500),
    ])->assertRedirect();

    expect($r->fresh()->logo_path)->toEndWith('.webp');
});

test('webp upload is accepted', function () {
    $r = settingsRestaurant();
    $admin = settingsAdmin($r);

    postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->image('logo.webp', 500, 500),
    ])->assertRedirect();

    expect($r->fresh()->logo_path)->toEndWith('.webp');
});

test('deleting a restaurant removes its asset directory', function () {
    $r = settingsRestaurant();
    $admin = settingsAdmin($r);

    postSettings($admin, $r, [
        'logo' => UploadedFile::fake()->image('logo.jpg', 200, 200),
    ])->assertRedirect();

    $path = $r->fresh()->logo_path;
    $disk = Storage::disk(RestaurantImageService::disk());
    expect($disk->exists($path))->toBeTrue();

    $r->delete();

    expect($disk->exists($path))->toBeFalse();
    expect($disk->directoryExists("restaurants/{$r->id}"))->toBeFalse();
});
