<?php

use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const SUPER_BASE = 'http://admin.plateful.test';

function validRestaurantPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Pasta Palace',
        'subdomain' => 'pasta',
        'email' => 'hello@pasta.test',
        'phone' => '555-1212',
        'street' => '1 Pasta Way',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
        'country' => 'US',
        'timezone' => 'America/New_York',
        'primary_color' => '#aa1111',
        'secondary_color' => '#ffffff',
        'description' => 'Italian comfort food.',
        'tax_rate_percent' => '8.25',
        'delivery_fee' => '3.50',
    ], $overrides);
}

test('super admin can create a restaurant', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload());

    $response->assertRedirect();

    $restaurant = Restaurant::query()->where('subdomain', 'pasta')->first();
    expect($restaurant)->not->toBeNull();
    expect($restaurant->name)->toBe('Pasta Palace');
    expect($restaurant->is_active)->toBeTrue();
    expect($restaurant->delivery_fee_cents)->toBe(350);
});

test('a blank tax rate is seeded from the state estimate', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'state' => 'NY',
            'tax_rate_percent' => null,
        ]))
        ->assertRedirect();

    $restaurant = Restaurant::query()->where('subdomain', 'pasta')->first();
    expect((float) $restaurant->tax_rate_percent)->toBe(8.54);
});

test('an explicit zero tax rate is honoured over the estimate', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'state' => 'NY',
            'tax_rate_percent' => '0',
        ]))
        ->assertRedirect();

    $restaurant = Restaurant::query()->where('subdomain', 'pasta')->first();
    expect((float) $restaurant->tax_rate_percent)->toBe(0.0);
});

test('non-super admin cannot create a restaurant', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload());

    $response->assertForbidden();

    expect(Restaurant::query()->where('subdomain', 'pasta')->exists())->toBeFalse();
});

test('non-super admin cannot view create page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->get(SUPER_BASE.'/super/restaurants/create');

    $response->assertForbidden();
});

test('reserved subdomain is rejected', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'subdomain' => 'admin',
        ]));

    $response->assertSessionHasErrors('subdomain');
    expect(Restaurant::query()->count())->toBe(0);
});

test('duplicate subdomain is rejected', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    Restaurant::factory()->create(['subdomain' => 'pasta']);

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'subdomain' => 'pasta',
        ]));

    $response->assertSessionHasErrors('subdomain');
});

test('uppercase subdomain is normalized to lowercase', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'subdomain' => 'PASTAplace',
        ]));

    $response->assertRedirect();
    expect(Restaurant::query()->where('subdomain', 'pastaplace')->exists())->toBeTrue();
});

test('invalid subdomain formats are rejected', function (string $sub) {
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'subdomain' => $sub,
        ]));

    $response->assertSessionHasErrors('subdomain');
})->with([
    '-leading',
    'trailing-',
    'double--hyphen',
    'has spaces',
    'has_underscores',
    'has.dot',
    'a',
]);

test('subdomain longer than 50 chars is rejected', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'subdomain' => str_repeat('a', 51),
        ]));

    $response->assertSessionHasErrors('subdomain');
});

test('missing required fields are rejected', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', [
            'subdomain' => 'whatever',
        ]);

    $response->assertSessionHasErrors(['name', 'email', 'timezone']);
});

test('delivery fee dollars are converted to cents', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'delivery_fee' => '4.99',
        ]));

    $restaurant = Restaurant::query()->where('subdomain', 'pasta')->first();
    expect($restaurant->delivery_fee_cents)->toBe(499);
});

test('providing owner_email creates an admin invitation and queues mail', function () {
    Mail::fake();
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload([
            'owner_email' => 'owner@pasta.test',
        ]));

    $response->assertRedirect();

    $restaurant = Restaurant::query()->where('subdomain', 'pasta')->first();
    $invitation = AdminInvitation::query()
        ->where('email', 'owner@pasta.test')
        ->where('restaurant_id', $restaurant->id)
        ->first();

    expect($invitation)->not->toBeNull();
    expect($invitation->as_super_admin)->toBeFalse();
    expect($invitation->invited_by_user_id)->toBe($superAdmin->id);

    Mail::assertQueued(AdminInvitationMail::class);
});

test('omitting owner_email creates no invitation', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->post(SUPER_BASE.'/super/restaurants', validRestaurantPayload());

    expect(AdminInvitation::query()->count())->toBe(0);
});
