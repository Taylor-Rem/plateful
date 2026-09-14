<?php

use App\Enums\ApiKeyScope;
use App\Mcp\Servers\PlatformServer;
use App\Mcp\Tools\ListGalleryPhotos;
use App\Mcp\Tools\RemoveImage;
use App\Mcp\Tools\UploadImage;
use App\Models\ApiKey;
use App\Models\RestaurantPhoto;
use App\Services\RestaurantImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Api/V1/ApiHelpers.php';
require_once __DIR__.'/../Storefront/CartTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Storage::fake(RestaurantImageService::disk());
});

function fakeImageBase64(string $name = 'photo.jpg'): string
{
    // Keep the fake alive until the bytes are read: its temp file goes with it.
    $file = UploadedFile::fake()->image($name, 500, 400);

    return base64_encode((string) file_get_contents($file->getRealPath()));
}

test('upload-image sets a menu item photo from base64 and remove-image clears it', function () {
    ['restaurant' => $restaurant, 'simple' => $soda] = cartFixture();
    $key = ApiKey::factory()->platform()->create();

    PlatformServer::actingAs($key, 'api-key')
        ->tool(UploadImage::class, ['restaurant' => 'marcos', 'target' => 'menu_item', 'menu_item_id' => $soda->id, 'image_base64' => 'data:image/jpeg;base64,'.fakeImageBase64()])
        ->assertOk()
        ->assertSee("/menu-items/{$soda->id}/");

    $path = $soda->fresh()->image_path;
    expect($path)->not->toBeNull();
    Storage::disk(RestaurantImageService::disk())->assertExists($path);

    PlatformServer::actingAs($key, 'api-key')
        ->tool(RemoveImage::class, ['restaurant' => 'marcos', 'target' => 'menu_item', 'menu_item_id' => $soda->id])
        ->assertOk()
        ->assertSee('"imageUrl":null');

    expect($soda->fresh()->image_path)->toBeNull();
});

test('upload-image sets branding from a URL and gallery photos round-trip', function () {
    ['restaurant' => $restaurant] = cartFixture();
    $key = ApiKey::factory()->platform()->create();
    $hero = UploadedFile::fake()->image('hero.png', 1400, 900);
    $bytes = file_get_contents($hero->getRealPath());
    Http::fake(['https://cdn.example.test/*' => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);

    PlatformServer::actingAs($key, 'api-key')
        ->tool(UploadImage::class, ['restaurant' => 'marcos', 'target' => 'hero', 'source_url' => 'https://cdn.example.test/hero.png'])
        ->assertOk()
        ->assertSee("/restaurants/{$restaurant->id}/hero/");
    expect($restaurant->fresh()->hero_image_path)->not->toBeNull();

    PlatformServer::actingAs($key, 'api-key')
        ->tool(UploadImage::class, ['restaurant' => 'marcos', 'target' => 'gallery', 'source_url' => 'https://cdn.example.test/room.png', 'caption' => 'The room'])
        ->assertOk()
        ->assertSee('"caption":"The room"');

    $photo = RestaurantPhoto::withoutTenantScope()->where('restaurant_id', $restaurant->id)->firstOrFail();

    PlatformServer::actingAs($key, 'api-key')
        ->tool(ListGalleryPhotos::class, ['restaurant' => 'marcos'])
        ->assertOk()
        ->assertSee('"id":'.$photo->id);

    PlatformServer::actingAs($key, 'api-key')
        ->tool(RemoveImage::class, ['restaurant' => 'marcos', 'target' => 'gallery', 'photo_id' => $photo->id])
        ->assertOk()
        ->assertSee('"removed":true');
    expect(RestaurantPhoto::withoutTenantScope()->find($photo->id))->toBeNull();

    PlatformServer::actingAs($key, 'api-key')
        ->tool(RemoveImage::class, ['restaurant' => 'marcos', 'target' => 'hero'])
        ->assertOk()
        ->assertSee('"heroImageUrl":null');
});

test('upload-image reports bad sources and missing scopes as tool errors', function () {
    ['restaurant' => $restaurant, 'simple' => $soda] = cartFixture();
    $key = ApiKey::factory()->platform()->create();
    $menuOnly = ApiKey::factory()->for($restaurant)->scopes([ApiKeyScope::MenuWrite])->create();

    PlatformServer::actingAs($key, 'api-key')
        ->tool(UploadImage::class, ['restaurant' => 'marcos', 'target' => 'logo'])
        ->assertHasErrors();

    PlatformServer::actingAs($key, 'api-key')
        ->tool(UploadImage::class, ['restaurant' => 'marcos', 'target' => 'logo', 'image_base64' => '!!not base64!!'])
        ->assertHasErrors(['image_base64 is not valid base64.']);

    PlatformServer::actingAs($key, 'api-key')
        ->tool(UploadImage::class, ['restaurant' => 'marcos', 'target' => 'menu_item', 'menu_item_id' => 999999, 'image_base64' => fakeImageBase64()])
        ->assertHasErrors(['No menu item [999999] at Marco\'s.']);

    PlatformServer::actingAs($menuOnly, 'api-key')
        ->tool(UploadImage::class, ['restaurant' => 'marcos', 'target' => 'logo', 'image_base64' => fakeImageBase64()])
        ->assertHasErrors(['This credential lacks the restaurants:write scope at Marco\'s.']);

    PlatformServer::actingAs($menuOnly, 'api-key')
        ->tool(UploadImage::class, ['restaurant' => 'marcos', 'target' => 'menu_item', 'menu_item_id' => $soda->id, 'image_base64' => fakeImageBase64()])
        ->assertOk();
});
