<?php

use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('restaurant_assets');
});

function heroRestaurant(string $sub = 'marcos'): Restaurant
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

function heroAdmin(Restaurant $r, string $role = 'admin'): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

function heroUrl(Restaurant $r): string
{
    return "http://{$r->subdomain}.plateful.test/admin/site/hero";
}

test('guest cannot update hero', function () {
    $r = heroRestaurant();

    $this->post(heroUrl($r), ['hero_tagline' => 'x'])
        ->assertRedirect();

    expect($r->fresh()->hero_tagline)->toBeNull();
});

test('non-member cannot update hero', function () {
    $r = heroRestaurant();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post(heroUrl($r), ['hero_tagline' => 'x'])
        ->assertForbidden();

    expect($r->fresh()->hero_tagline)->toBeNull();
});

test('staff cannot update hero', function () {
    $r = heroRestaurant();
    $staff = heroAdmin($r, 'staff');

    $this->actingAs($staff)
        ->post(heroUrl($r), ['hero_tagline' => 'x'])
        ->assertForbidden();

    expect($r->fresh()->hero_tagline)->toBeNull();
});

test('admin can update tagline and cta', function () {
    $r = heroRestaurant();
    $admin = heroAdmin($r);

    $this->actingAs($admin)
        ->post(heroUrl($r), [
            'hero_tagline' => 'Wood-fired pizza since 2014',
            'hero_cta_label' => 'Start your order',
            'hero_cta_url' => '#menu',
        ])
        ->assertRedirect();

    $fresh = $r->fresh();
    expect($fresh->hero_tagline)->toBe('Wood-fired pizza since 2014')
        ->and($fresh->hero_cta_label)->toBe('Start your order')
        ->and($fresh->hero_cta_url)->toBe('#menu');
});

test('admin can upload hero image and all webp variants are written', function () {
    $r = heroRestaurant();
    $admin = heroAdmin($r);

    $this->actingAs($admin)
        ->post(heroUrl($r), [
            'image' => UploadedFile::fake()->image('hero.jpg', 1000, 600),
        ])
        ->assertRedirect();

    $fresh = $r->fresh();
    expect($fresh->hero_image_path)->not->toBeNull()
        ->and($fresh->hero_image_path)->toStartWith("restaurants/{$r->id}/hero/")
        ->and($fresh->hero_image_path)->toEndWith('.webp');

    $disk = Storage::disk('restaurant_assets');
    foreach (app(RestaurantImageService::class)->variantPaths($fresh->hero_image_path) as $variant) {
        expect($disk->exists($variant))->toBeTrue();
    }
});

test('replacing the hero image deletes prior variants', function () {
    $r = heroRestaurant();
    $admin = heroAdmin($r);

    $this->actingAs($admin)
        ->post(heroUrl($r), ['image' => UploadedFile::fake()->image('first.jpg', 1000, 600)])
        ->assertRedirect();

    $oldPath = $r->fresh()->hero_image_path;
    $oldVariants = app(RestaurantImageService::class)->variantPaths($oldPath);

    $this->actingAs($admin)
        ->post(heroUrl($r), ['image' => UploadedFile::fake()->image('second.jpg', 1000, 600)])
        ->assertRedirect();

    $disk = Storage::disk('restaurant_assets');
    foreach ($oldVariants as $variant) {
        expect($disk->exists($variant))->toBeFalse();
    }

    $newPath = $r->fresh()->hero_image_path;
    foreach (app(RestaurantImageService::class)->variantPaths($newPath) as $variant) {
        expect($disk->exists($variant))->toBeTrue();
    }
});

test('remove_image flag clears the hero image', function () {
    $r = heroRestaurant();
    $admin = heroAdmin($r);

    $this->actingAs($admin)
        ->post(heroUrl($r), ['image' => UploadedFile::fake()->image('hero.jpg', 1000, 600)])
        ->assertRedirect();

    expect($r->fresh()->hero_image_path)->not->toBeNull();

    $this->actingAs($admin)
        ->post(heroUrl($r), ['remove_image' => '1'])
        ->assertRedirect();

    expect($r->fresh()->hero_image_path)->toBeNull();
});

test('oversized image is rejected', function () {
    $r = heroRestaurant();
    $admin = heroAdmin($r);

    $this->actingAs($admin)
        ->post(heroUrl($r), [
            'image' => UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg'),
        ])
        ->assertSessionHasErrors('image');

    expect($r->fresh()->hero_image_path)->toBeNull();
});

test('disallowed mime is rejected', function () {
    $r = heroRestaurant();
    $admin = heroAdmin($r);

    $this->actingAs($admin)
        ->post(heroUrl($r), [
            'image' => UploadedFile::fake()->create('hero.gif', 500, 'image/gif'),
        ])
        ->assertSessionHasErrors('image');

    expect($r->fresh()->hero_image_path)->toBeNull();
});

test('cta label rejected when too long', function () {
    $r = heroRestaurant();
    $admin = heroAdmin($r);

    $this->actingAs($admin)
        ->post(heroUrl($r), [
            'hero_cta_label' => str_repeat('x', 65),
        ])
        ->assertSessionHasErrors('hero_cta_label');
});

test('storefront home includes hero data in restaurant prop', function () {
    $r = heroRestaurant();
    $r->update([
        'hero_tagline' => 'Best slice in town',
        'hero_cta_label' => 'Order now',
        'hero_cta_url' => '#menu',
    ]);

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->where('restaurant.heroTagline', 'Best slice in town')
                ->where('restaurant.heroCtaLabel', 'Order now')
                ->where('restaurant.heroCtaUrl', '#menu')
        );
});
