<?php

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\User;
use Pest\Browser\Playwright\Playwright;

require_once __DIR__.'/../Feature/Campaigns/CampaignTestHelpers.php';

// The admin console is domain-routed; this makes the plugin's in-process
// server present every request with the admin host so those routes match.
beforeEach(function () {
    Playwright::setHost('admin.plateful.test');
    config(['platform.primary_domain' => 'plateful.test']);
    config(['services.resend.key' => null]);
});

function campaignBrowserRestaurant(): Restaurant
{
    $r = Restaurant::create([
        'name' => 'Compose Pizzeria',
        'subdomain' => 'composejoint',
        'email' => 'hello@composejoint.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);

    $r->forceFill([
        'status' => RestaurantStatus::Active,
        'is_active' => true,
        'stripe_account_status' => Restaurant::STRIPE_ENABLED,
    ])->save();

    return $r;
}

test('the compose page shows a live recipient count and a server-rendered preview', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = campaignBrowserRestaurant();
    $admin->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Admin->value]);

    optedInCustomer($restaurant, 'Alice Apple', 'alice@example.test');
    optedInCustomer($restaurant, 'Bob Banana', 'bob@example.test');

    $this->actingAs($admin);

    $page = visit('/composejoint/campaigns/create');

    $page->assertNoJavaScriptErrors()
        ->assertSee('New campaign')
        // The live count resolved 2 eligible recipients via the JSON endpoint.
        ->assertSee('2')
        ->assertSee('recipients will get this email');

    $page->fill('#subject', 'Slow Tuesday: half-price pies')
        ->fill('#headline', 'Half-price pies this Tuesday')
        ->fill('#body', 'Come hungry. Leave happy.');

    // The debounced preview posts to the server and renders into the iframe.
    $page->wait(2);
    $html = $page->script("document.querySelector('iframe[title=\"Email preview\"]')?.getAttribute('srcdoc') ?? ''");

    expect($html)->toContain('Half-price pies this Tuesday')
        ->toContain('Sent via Plateful')
        ->toContain('Unsubscribe');
});

test('the campaigns index empty state sells the feature with the opted-in count', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = campaignBrowserRestaurant();
    $admin->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Admin->value]);

    optedInCustomer($restaurant, 'Alice Apple', 'alice@example.test');

    $this->actingAs($admin);

    visit('/composejoint/campaigns')
        ->assertNoJavaScriptErrors()
        ->assertSee('You have 1 customer opted into marketing')
        ->assertSee('Create your first campaign');
});
