<?php

use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(RestaurantImageService::disk());
});

function aboutRestaurant(string $sub = 'marcos'): Restaurant
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

function aboutAdmin(Restaurant $r, string $role = 'admin'): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

function aboutUrl(Restaurant $r): string
{
    return "http://{$r->subdomain}.plateful.test/admin/site/about";
}

test('guest cannot update about', function () {
    $r = aboutRestaurant();

    $this->post(aboutUrl($r), ['about_body' => 'x'])
        ->assertRedirect();

    expect($r->fresh()->about_body)->toBeNull();
});

test('staff cannot update about', function () {
    $r = aboutRestaurant();
    $staff = aboutAdmin($r, 'staff');

    $this->actingAs($staff)
        ->post(aboutUrl($r), ['about_body' => 'x'])
        ->assertForbidden();

    expect($r->fresh()->about_body)->toBeNull();
});

test('admin can update about body', function () {
    $r = aboutRestaurant();
    $admin = aboutAdmin($r);

    $this->actingAs($admin)
        ->post(aboutUrl($r), [
            'about_body' => "We've been slinging pies since 1998.\n\nFamily-owned and proud.",
        ])
        ->assertRedirect();

    expect($r->fresh()->about_body)->toContain('slinging pies');
});

test('admin can upload about image and all webp variants are written', function () {
    $r = aboutRestaurant();
    $admin = aboutAdmin($r);

    $this->actingAs($admin)
        ->post(aboutUrl($r), [
            'image' => UploadedFile::fake()->image('about.jpg', 800, 600),
        ])
        ->assertRedirect();

    $fresh = $r->fresh();
    expect($fresh->about_image_path)->not->toBeNull()
        ->and($fresh->about_image_path)->toStartWith("restaurants/{$r->id}/about/")
        ->and($fresh->about_image_path)->toEndWith('.webp');

    $disk = Storage::disk(RestaurantImageService::disk());
    foreach (app(RestaurantImageService::class)->variantPaths($fresh->about_image_path) as $variant) {
        expect($disk->exists($variant))->toBeTrue();
    }
});

test('replacing the about image deletes prior variants', function () {
    $r = aboutRestaurant();
    $admin = aboutAdmin($r);

    $this->actingAs($admin)
        ->post(aboutUrl($r), ['image' => UploadedFile::fake()->image('first.jpg', 800, 600)])
        ->assertRedirect();

    $oldPath = $r->fresh()->about_image_path;
    $oldVariants = app(RestaurantImageService::class)->variantPaths($oldPath);

    $this->actingAs($admin)
        ->post(aboutUrl($r), ['image' => UploadedFile::fake()->image('second.jpg', 800, 600)])
        ->assertRedirect();

    $disk = Storage::disk(RestaurantImageService::disk());
    foreach ($oldVariants as $variant) {
        expect($disk->exists($variant))->toBeFalse();
    }
});

test('remove_image clears the about image', function () {
    $r = aboutRestaurant();
    $admin = aboutAdmin($r);

    $this->actingAs($admin)
        ->post(aboutUrl($r), ['image' => UploadedFile::fake()->image('about.jpg', 800, 600)])
        ->assertRedirect();

    expect($r->fresh()->about_image_path)->not->toBeNull();

    $this->actingAs($admin)
        ->post(aboutUrl($r), ['remove_image' => '1'])
        ->assertRedirect();

    expect($r->fresh()->about_image_path)->toBeNull();
});

test('about_body length is capped', function () {
    $r = aboutRestaurant();
    $admin = aboutAdmin($r);

    $this->actingAs($admin)
        ->post(aboutUrl($r), [
            'about_body' => str_repeat('x', 5001),
        ])
        ->assertSessionHasErrors('about_body');
});

test('storefront home includes about data in restaurant prop', function () {
    $r = aboutRestaurant();
    $r->update(['about_body' => 'Our story starts in Naples.']);

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->where('restaurant.aboutBody', 'Our story starts in Naples.')
        );
});
