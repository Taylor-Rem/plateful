<?php

use App\Models\Restaurant;
use App\Models\RestaurantPhoto;
use App\Models\User;
use App\Services\RestaurantImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(RestaurantImageService::disk());
});

function photoRestaurant(string $sub = 'marcos'): Restaurant
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

function photoAdmin(Restaurant $r, string $role = 'admin'): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

function photoUrl(Restaurant $r, string $path = ''): string
{
    return "http://{$r->subdomain}.plateful.test/admin/site/photos{$path}";
}

test('guest cannot upload photo', function () {
    $r = photoRestaurant();

    $this->post(photoUrl($r), ['image' => UploadedFile::fake()->image('p.jpg', 800, 600)])
        ->assertRedirect();

    expect(RestaurantPhoto::withoutTenantScope()->count())->toBe(0);
});

test('staff cannot upload photo', function () {
    $r = photoRestaurant();
    $staff = photoAdmin($r, 'staff');

    $this->actingAs($staff)
        ->post(photoUrl($r), ['image' => UploadedFile::fake()->image('p.jpg', 800, 600)])
        ->assertForbidden();

    expect(RestaurantPhoto::withoutTenantScope()->count())->toBe(0);
});

test('admin can upload a photo with caption and variants are written', function () {
    $r = photoRestaurant();
    $admin = photoAdmin($r);

    $this->actingAs($admin)
        ->post(photoUrl($r), [
            'image' => UploadedFile::fake()->image('p.jpg', 800, 600),
            'caption' => 'Wood-fired oven',
        ])
        ->assertRedirect();

    $photo = RestaurantPhoto::withoutTenantScope()->first();
    expect($photo)->not->toBeNull()
        ->and($photo->caption)->toBe('Wood-fired oven')
        ->and($photo->position)->toBe(0)
        ->and($photo->image_path)->toStartWith("restaurants/{$r->id}/gallery/{$photo->id}/")
        ->and($photo->image_path)->toEndWith('.webp');

    $disk = Storage::disk(RestaurantImageService::disk());
    foreach (app(RestaurantImageService::class)->variantPaths($photo->image_path) as $variant) {
        expect($disk->exists($variant))->toBeTrue();
    }
});

test('position auto-increments for additional photos', function () {
    $r = photoRestaurant();
    $admin = photoAdmin($r);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($admin)
            ->post(photoUrl($r), ['image' => UploadedFile::fake()->image("p{$i}.jpg", 600, 400)])
            ->assertRedirect();
    }

    $positions = RestaurantPhoto::withoutTenantScope()
        ->where('restaurant_id', $r->id)
        ->orderBy('id')
        ->pluck('position')
        ->all();

    expect($positions)->toBe([0, 1, 2]);
});

test('admin can update a photo caption', function () {
    $r = photoRestaurant();
    $admin = photoAdmin($r);

    $this->actingAs($admin)
        ->post(photoUrl($r), ['image' => UploadedFile::fake()->image('p.jpg', 600, 400)])
        ->assertRedirect();

    $photo = RestaurantPhoto::withoutTenantScope()->first();

    $this->actingAs($admin)
        ->patch(photoUrl($r, "/{$photo->id}"), ['caption' => 'New caption'])
        ->assertRedirect();

    expect($photo->fresh()->caption)->toBe('New caption');
});

test('admin can reorder photos', function () {
    $r = photoRestaurant();
    $admin = photoAdmin($r);

    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($admin)
            ->post(photoUrl($r), ['image' => UploadedFile::fake()->image("p{$i}.jpg", 400, 400)]);
        $ids[] = RestaurantPhoto::withoutTenantScope()->orderByDesc('id')->first()->id;
    }

    // Reverse order.
    $reversed = array_reverse($ids);
    $this->actingAs($admin)
        ->post(photoUrl($r, '/reorder'), ['ids' => $reversed])
        ->assertRedirect();

    $orderedIds = RestaurantPhoto::withoutTenantScope()
        ->where('restaurant_id', $r->id)
        ->orderBy('position')
        ->pluck('id')
        ->all();

    expect($orderedIds)->toBe($reversed);
});

test('admin can delete a photo and variants are removed', function () {
    $r = photoRestaurant();
    $admin = photoAdmin($r);

    $this->actingAs($admin)
        ->post(photoUrl($r), ['image' => UploadedFile::fake()->image('p.jpg', 600, 400)])
        ->assertRedirect();

    $photo = RestaurantPhoto::withoutTenantScope()->first();
    $variants = app(RestaurantImageService::class)->variantPaths($photo->image_path);

    $this->actingAs($admin)
        ->delete(photoUrl($r, "/{$photo->id}"))
        ->assertRedirect();

    expect(RestaurantPhoto::withoutTenantScope()->count())->toBe(0);

    $disk = Storage::disk(RestaurantImageService::disk());
    foreach ($variants as $v) {
        expect($disk->exists($v))->toBeFalse();
    }
});

test('cross-tenant access is blocked', function () {
    $r1 = photoRestaurant('marcos');
    $r2 = photoRestaurant('luigis');
    $r1Admin = photoAdmin($r1);

    // Upload one photo to r2 directly.
    $r2Photo = RestaurantPhoto::withoutTenantScope()->create([
        'restaurant_id' => $r2->id,
        'image_path' => 'restaurants/'.$r2->id.'/gallery/x/y.webp',
        'caption' => 'r2 photo',
        'position' => 0,
    ]);

    // r1 admin tries to edit r2's photo on the r1 host — should 404.
    $this->actingAs($r1Admin)
        ->patch(photoUrl($r1, "/{$r2Photo->id}"), ['caption' => 'hacked'])
        ->assertNotFound();

    expect($r2Photo->fresh()->caption)->toBe('r2 photo');
});

test('caption length is capped', function () {
    $r = photoRestaurant();
    $admin = photoAdmin($r);

    $this->actingAs($admin)
        ->post(photoUrl($r), [
            'image' => UploadedFile::fake()->image('p.jpg', 400, 400),
            'caption' => str_repeat('x', 141),
        ])
        ->assertSessionHasErrors('caption');
});

test('storefront home includes photos prop', function () {
    $r = photoRestaurant();
    $admin = photoAdmin($r);

    $this->actingAs($admin)
        ->post(photoUrl($r), [
            'image' => UploadedFile::fake()->image('p.jpg', 400, 400),
            'caption' => 'Hello',
        ])
        ->assertRedirect();

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->has('photos', 1)
                ->where('photos.0.caption', 'Hello')
        );
});
