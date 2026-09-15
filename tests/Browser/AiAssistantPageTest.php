<?php

use App\Enums\RestaurantRole;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Models\User;
use Pest\Browser\Playwright\Playwright;

beforeEach(function () {
    Playwright::setHost('admin.plateful.test');
});

function aiBrowserRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'Browser Bistro',
        'subdomain' => 'aijoint',
        'email' => 'hello@aijoint.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('a restaurant admin creates a key on the AI assistant page and sees the setup once', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = aiBrowserRestaurant();
    $admin->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Admin->value]);

    $this->actingAs($admin);

    $page = visit('/aijoint/settings/ai')
        ->assertNoJavaScriptErrors()
        ->assertSee('AI assistant')
        ->assertSee('Create a key')
        ->assertSee('No assistant connected yet')
        ->assertSee('Nothing yet');

    $page->fill('#key-name', 'Claude on the laptop')
        ->click('Create key');

    // Inertia posts, the server redirects back with the one-time key.
    // Inertia posts, the server redirects back with the one-time key.
    $page->wait(3);

    $page->assertNoJavaScriptErrors()
        ->assertSee('copy it now')
        ->assertSee('pfk_test_')
        ->assertSee('claude mcp add --transport http plateful')
        ->assertSee('/mcp/platform/pfk_test_')
        ->assertSee('Claude on the laptop')
        ->screenshot(filename: 'ai-assistant-created-key');

    $key = ApiKey::query()->sole();

    expect($key->name)->toBe('Claude on the laptop')
        ->and($key->restaurant_id)->toBe($restaurant->id)
        ->and($key->rate_limit_per_minute)->toBe(60)
        // Enum order: the pre-ticked "recommended" set.
        ->and($key->scopes)->toBe(['restaurants:read', 'restaurants:write', 'orders:read', 'menu:read', 'menu:write']);
});
