<?php

use App\Enums\ApiKeyScope;
use App\Models\RestaurantPhoto;
use App\Models\User;
use App\Services\RestaurantImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../ApiHelpers.php';
require_once __DIR__.'/../../../Storefront/CartTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Storage::fake(RestaurantImageService::disk());
});

test('a menu item image uploads as multipart, writes three webp variants, and can be removed', function () {
    ['restaurant' => $restaurant, 'simple' => $soda] = cartFixture();

    $this->withToken(apiKeyFor($restaurant));

    $response = $this->post(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), [
        'image' => UploadedFile::fake()->image('soda.jpg', 900, 700),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.id', $soda->id);

    expect($response->json('data.imageUrl'))->toContain("/menu-items/{$soda->id}/")->toEndWith('.webp')
        ->and($response->json('data.imageThumbUrl'))->toEndWith('-thumb.webp');

    $path = $soda->fresh()->image_path;
    $disk = Storage::disk(RestaurantImageService::disk());
    foreach (app(RestaurantImageService::class)->variantPaths($path) as $variant) {
        $disk->assertExists($variant);
    }

    $this->deleteJson(operatorUrl($restaurant, "menu-items/{$soda->id}/image"))
        ->assertOk()
        ->assertJsonPath('data.imageUrl', null);

    expect($soda->fresh()->image_path)->toBeNull();
    $disk->assertMissing($path);
});

test('an image can be fetched from a URL instead of posted', function () {
    ['restaurant' => $restaurant, 'simple' => $soda] = cartFixture();
    $remote = UploadedFile::fake()->image('remote.png', 400, 400);
    $bytes = file_get_contents($remote->getRealPath());
    // Specific before wildcard: fakes match in order.
    Http::fake([
        'https://cdn.example.test/missing.png' => Http::response('', 404),
        'https://cdn.example.test/*' => Http::response($bytes, 200, ['Content-Type' => 'image/png']),
    ]);

    $this->withToken(apiKeyFor($restaurant));

    $this->postJson(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), ['source_url' => 'https://cdn.example.test/soda.png'])
        ->assertOk()
        ->assertJsonPath('data.id', $soda->id);
    expect($soda->fresh()->image_path)->not->toBeNull();

    $this->postJson(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), ['source_url' => 'https://cdn.example.test/missing.png'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Could not fetch https://cdn.example.test/missing.png: HTTP 404.');

    $this->postJson(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['image', 'source_url']);

    $this->postJson(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), ['source_url' => 'ftp://cdn.example.test/soda.png'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_url']);
});

test('a non-image payload is refused', function () {
    ['restaurant' => $restaurant, 'simple' => $soda] = cartFixture();
    Http::fake(['https://cdn.example.test/*' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'image/png'])]);

    $this->withToken(apiKeyFor($restaurant))
        ->postJson(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), ['source_url' => 'https://cdn.example.test/fake.png'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Unsupported image type [text/html]'));
});

test('logo, hero and about slots upload and clear, replacing the previous file', function (string $kind, string $column) {
    ['restaurant' => $restaurant] = cartFixture();
    $disk = Storage::disk(RestaurantImageService::disk());

    $this->withToken(apiKeyFor($restaurant));

    $first = $this->post(operatorUrl($restaurant, "images/{$kind}"), ['image' => UploadedFile::fake()->image('a.jpg', 800, 800)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.restaurantId', $restaurant->id);
    $firstPath = $restaurant->fresh()->{$column};
    expect($firstPath)->toContain("restaurants/{$restaurant->id}/{$kind}/");
    $disk->assertExists($firstPath);

    $this->post(operatorUrl($restaurant, "images/{$kind}"), ['image' => UploadedFile::fake()->image('b.png', 800, 800)], ['Accept' => 'application/json'])
        ->assertOk();
    $secondPath = $restaurant->fresh()->{$column};
    expect($secondPath)->not->toBe($firstPath);
    $disk->assertMissing($firstPath)->assertExists($secondPath);

    $this->deleteJson(operatorUrl($restaurant, "images/{$kind}"))->assertOk();
    expect($restaurant->fresh()->{$column})->toBeNull();
    $disk->assertMissing($secondPath);

    $this->deleteJson(operatorUrl($restaurant, 'images/banner'))->assertNotFound();
})->with([
    'logo' => ['logo', 'logo_path'],
    'hero' => ['hero', 'hero_image_path'],
    'about' => ['about', 'about_image_path'],
]);

test('gallery photos are listed, added with captions, and removed', function () {
    ['restaurant' => $restaurant] = cartFixture();
    $other = cartFixture('luigis')['restaurant'];
    $disk = Storage::disk(RestaurantImageService::disk());

    $this->withToken(apiKeyFor($restaurant));

    $this->getJson(operatorUrl($restaurant, 'photos'))->assertOk()->assertJsonCount(0, 'data');

    $one = $this->post(operatorUrl($restaurant, 'photos'), ['image' => UploadedFile::fake()->image('dining.jpg', 1200, 800), 'caption' => 'Dining room'], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.caption', 'Dining room')
        ->assertJsonPath('data.position', 0);
    $this->post(operatorUrl($restaurant, 'photos'), ['image' => UploadedFile::fake()->image('patio.jpg', 1200, 800)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.caption', null)
        ->assertJsonPath('data.position', 1);

    $this->getJson(operatorUrl($restaurant, 'photos'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.caption', 'Dining room');

    $photoId = $one->json('data.id');
    $path = RestaurantPhoto::withoutTenantScope()->findOrFail($photoId)->image_path;
    $disk->assertExists($path);

    // Another restaurant's URL cannot reach this photo.
    forgetApiGuards();
    $this->withToken(apiKeyFor($other))->deleteJson(operatorUrl($other, "photos/{$photoId}"))->assertNotFound();
    forgetApiGuards();

    $this->withToken(apiKeyFor($restaurant))->deleteJson(operatorUrl($restaurant, "photos/{$photoId}"))->assertNoContent();
    expect(RestaurantPhoto::withoutTenantScope()->find($photoId))->toBeNull();
    $disk->assertMissing($path);
});

test('branding writes need restaurants:write; staff and menu-only keys are refused', function () {
    ['restaurant' => $restaurant, 'simple' => $soda] = cartFixture();
    $staff = User::factory()->create();
    $staff->restaurants()->attach($restaurant->id, ['role' => 'staff']);
    $image = fn () => ['image' => UploadedFile::fake()->image('x.jpg', 300, 300)];
    $json = ['Accept' => 'application/json'];

    // Staff hold no write scope on the menu or branding; they can read the gallery.
    $this->withToken(operatorTokenFor($staff));
    $this->post(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), $image(), $json)->assertForbidden();
    $this->post(operatorUrl($restaurant, 'images/logo'), $image(), $json)->assertForbidden();
    $this->post(operatorUrl($restaurant, 'photos'), $image(), $json)->assertForbidden();
    $this->getJson(operatorUrl($restaurant, 'photos'))->assertOk();
    forgetApiGuards();

    // A key with menu:write only: same split.
    $this->withToken(apiKeyFor($restaurant, [ApiKeyScope::MenuWrite]));
    $this->post(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), $image(), $json)->assertOk();
    $this->post(operatorUrl($restaurant, 'images/hero'), $image(), $json)->assertForbidden();
    $this->deleteJson(operatorUrl($restaurant, 'images/hero'))->assertForbidden();
    forgetApiGuards();

    // A key with restaurants:write only: branding yes, menu no.
    $this->withToken(apiKeyFor($restaurant, [ApiKeyScope::RestaurantsWrite]));
    $this->post(operatorUrl($restaurant, 'images/hero'), $image(), $json)->assertOk();
    $this->post(operatorUrl($restaurant, "menu-items/{$soda->id}/image"), $image(), $json)->assertForbidden();
});
