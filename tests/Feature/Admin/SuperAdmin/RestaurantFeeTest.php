<?php

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\CartManager;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../../Storefront/CartTestHelpers.php';
require_once __DIR__.'/../../Storefront/CheckoutTestHelpers.php';

const SUPER_FEE_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('the shipped platform default fee is a flat 4 percent', function () {
    // Guards the shipped default — no config override, no explicit rate.
    expect((float) config('platform.default_application_fee_percent'))->toBe(4.00);

    $restaurant = Restaurant::factory()->create();

    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(4.00);
});

test('new restaurant picks up the configured platform default fee', function () {
    config(['platform.default_application_fee_percent' => 2.50]);

    // The factory does not set application_fee_percent, so creation should
    // fall through to the configured platform default.
    $restaurant = Restaurant::factory()->create();

    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(2.50);
});

test('an explicit fee at creation overrides the platform default', function () {
    config(['platform.default_application_fee_percent' => 1.00]);

    $restaurant = Restaurant::factory()->create(['application_fee_percent' => 3.25]);

    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(3.25);
});

test('changing the platform default does not change existing restaurants', function () {
    config(['platform.default_application_fee_percent' => 1.00]);
    $restaurant = Restaurant::factory()->create();
    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(1.00);

    // A later sign-up cohort gets a different rate...
    config(['platform.default_application_fee_percent' => 5.00]);
    $newer = Restaurant::factory()->create();

    // ...but the grandfathered restaurant is untouched.
    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(1.00);
    expect((float) $newer->fresh()->application_fee_percent)->toBe(5.00);
});

test('super admin can update a restaurant fee via the console', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'marcos',
        'application_fee_percent' => 1.00,
    ]);

    $response = $this->actingAs($superAdmin)
        ->put(SUPER_FEE_BASE."/super/restaurants/{$restaurant->subdomain}/fee", [
            'application_fee_percent' => 0.75,
        ]);

    $response->assertRedirect();
    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(0.75);
});

test('a tenant admin cannot update a restaurant fee', function () {
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'marcos',
        'application_fee_percent' => 1.00,
    ]);
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant, ['role' => RestaurantRole::Admin->value]);

    $response = $this->actingAs($admin)
        ->put(SUPER_FEE_BASE."/super/restaurants/{$restaurant->subdomain}/fee", [
            'application_fee_percent' => 0.0,
        ]);

    $response->assertForbidden();
    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(1.00);
});

test('a tenant staff member cannot update a restaurant fee', function () {
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'marcos',
        'application_fee_percent' => 1.00,
    ]);
    $staff = User::factory()->create();
    $staff->restaurants()->attach($restaurant, ['role' => RestaurantRole::Staff->value]);

    $response = $this->actingAs($staff)
        ->put(SUPER_FEE_BASE."/super/restaurants/{$restaurant->subdomain}/fee", [
            'application_fee_percent' => 0.0,
        ]);

    $response->assertForbidden();
    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(1.00);
});

test('a non-admin user cannot update a restaurant fee', function () {
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'marcos',
        'application_fee_percent' => 1.00,
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->put(SUPER_FEE_BASE."/super/restaurants/{$restaurant->subdomain}/fee", [
            'application_fee_percent' => 0.0,
        ]);

    $response->assertForbidden();
    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(1.00);
});

test('fee validation rejects invalid values', function (mixed $value) {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'marcos',
        'application_fee_percent' => 1.00,
    ]);

    $response = $this->actingAs($superAdmin)
        ->put(SUPER_FEE_BASE."/super/restaurants/{$restaurant->subdomain}/fee", [
            'application_fee_percent' => $value,
        ]);

    $response->assertSessionHasErrors('application_fee_percent');
    expect((float) $restaurant->fresh()->application_fee_percent)->toBe(1.00);
})->with([
    'negative' => -1,
    'just over the ceiling' => 15.01,
    // The reason the ceiling exists: 40% is a plausible fat finger, and it is
    // the rate the delivery apps charge — exactly what Plateful undercuts.
    'a fat-fingered predatory rate' => 40,
    'over 100' => 101,
    'too many decimals' => 1.234,
    'non-numeric' => 'abc',
    'null' => null,
]);

test('fee validation accepts values up to the ceiling', function (mixed $value) {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'marcos',
        'application_fee_percent' => 1.00,
    ]);

    $response = $this->actingAs($superAdmin)
        ->put(SUPER_FEE_BASE."/super/restaurants/{$restaurant->subdomain}/fee", [
            'application_fee_percent' => $value,
        ]);

    $response->assertSessionHasNoErrors();
    expect((float) $restaurant->fresh()->application_fee_percent)->toBe((float) $value);
})->with([
    'zero (a comped restaurant)' => 0,
    'the locked 4 percent' => 4,
    'the ceiling itself' => 15,
]);

test('placed order computes the application fee from the per-restaurant rate', function () {
    Mail::fake();

    $f = cartFixture();
    $r = $f['restaurant'];
    // Override this restaurant to a non-default 2% rate.
    $r->application_fee_percent = 2.00;
    $r->save();

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'Alice Customer',
            'customer_email' => 'alice@example.test',
            'type' => 'pickup',
            'tip_preset' => '0',
        ]);

    $order = payLatestCheckout();

    // Fee is floor(subtotal * rate / 100), on the food subtotal only — and at
    // 2% it must differ from a 1% computation, proving the per-restaurant value
    // is what's read (rather than any fixed rate).
    expect($order->application_fee_cents)->toBe((int) floor($order->subtotal_cents * 2 / 100));
    expect($order->application_fee_cents)->not->toBe((int) floor($order->subtotal_cents * 1 / 100));
});
