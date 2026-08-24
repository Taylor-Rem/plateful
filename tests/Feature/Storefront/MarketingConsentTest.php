<?php

use App\Enums\MarketingConsentSource;
use App\Models\MarketingConsentEvent;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use App\Services\CartManager;
use App\Services\MarketingConsentService;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

function consentCustomer(string $email = 'alice@example.test'): User
{
    return User::create([
        'name' => 'Alice', 'email' => $email,
        'password' => Hash::make('password'), 'is_super_admin' => false,
    ]);
}

/**
 * Place a paid pickup order at the fixture restaurant with the given payload.
 *
 * @param  array{restaurant: Restaurant, item: mixed, size_medium: mixed, top_pepperoni: mixed}  $f
 * @param  array<string, mixed>  $extra
 */
function placeOrderWithConsent(mixed $test, array $f, User $user, array $extra = []): void
{
    $r = $f['restaurant'];

    $first = $test->actingAs($user)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
            'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
        ]);
    $cookie = cartCookieFrom($first);

    fakeCheckoutSession();
    $test->actingAs($user)
        ->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", array_merge([
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'type' => 'pickup',
        ], $extra));
    payLatestCheckout();
}

test('checking the marketing box at checkout persists consent and an audit event', function () {
    $f = cartFixture('marcos');
    $user = consentCustomer();

    placeOrderWithConsent($this, $f, $user, ['marketing_opt_in' => true]);

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)
        ->where('restaurant_id', $f['restaurant']->id)
        ->first();
    expect($pivot->isEmailOptedIn())->toBeTrue();

    $event = MarketingConsentEvent::query()->sole();
    expect($event->user_id)->toBe($user->id)
        ->and($event->restaurant_id)->toBe($f['restaurant']->id)
        ->and($event->channel->value)->toBe('email')
        ->and($event->action->value)->toBe('opted_in')
        ->and($event->source)->toBe(MarketingConsentSource::Checkout)
        ->and($event->consent_text_snapshot)->toContain($f['restaurant']->name)
        ->and($event->ip)->not->toBeNull();
});

test('an unchecked box records nothing', function () {
    $f = cartFixture('marcos');
    $user = consentCustomer();

    placeOrderWithConsent($this, $f, $user);

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)
        ->where('restaurant_id', $f['restaurant']->id)
        ->first();
    expect($pivot->isEmailOptedIn())->toBeFalse();
    expect(MarketingConsentEvent::count())->toBe(0);
});

test('consent is scoped to the restaurant it was given at', function () {
    $a = cartFixture('marcos');
    $b = cartFixture('bobs');
    $user = consentCustomer('dana@example.test');

    placeOrderWithConsent($this, $a, $user, ['marketing_opt_in' => true]);
    app(CurrentTenant::class)->clear();
    placeOrderWithConsent($this, $b, $user);

    $atMarcos = RestaurantCustomer::query()
        ->where('user_id', $user->id)->where('restaurant_id', $a['restaurant']->id)->first();
    $atBobs = RestaurantCustomer::query()
        ->where('user_id', $user->id)->where('restaurant_id', $b['restaurant']->id)->first();

    expect($atMarcos->isEmailOptedIn())->toBeTrue();
    expect($atBobs->isEmailOptedIn())->toBeFalse();
    expect(MarketingConsentEvent::query()->where('restaurant_id', $b['restaurant']->id)->count())->toBe(0);
});

test('opting in is idempotent — no duplicate audit events', function () {
    $r = Restaurant::create([
        'name' => 'M', 'subdomain' => 'marcos', 'email' => 'm@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);
    $user = consentCustomer();
    $service = app(MarketingConsentService::class);

    $service->optInEmail($user, $r, MarketingConsentSource::Checkout);
    $service->optInEmail($user, $r, MarketingConsentSource::Checkout);

    expect(MarketingConsentEvent::count())->toBe(1);
});

test('the account toggle opts in and out with audit events', function () {
    $r = Restaurant::create([
        'name' => 'M', 'subdomain' => 'marcos', 'email' => 'm@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);
    $user = consentCustomer();

    $this->actingAs($user)
        ->patch("http://{$r->subdomain}.plateful.test/account/marketing", ['opted_in' => true])
        ->assertRedirect();

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)->where('restaurant_id', $r->id)->first();
    expect($pivot->isEmailOptedIn())->toBeTrue();

    $this->actingAs($user)
        ->patch("http://{$r->subdomain}.plateful.test/account/marketing", ['opted_in' => false])
        ->assertRedirect();

    expect($pivot->fresh()->isEmailOptedIn())->toBeFalse();

    $actions = MarketingConsentEvent::query()->orderBy('id')->get();
    expect($actions)->toHaveCount(2)
        ->and($actions[0]->action->value)->toBe('opted_in')
        ->and($actions[0]->source)->toBe(MarketingConsentSource::Account)
        ->and($actions[1]->action->value)->toBe('opted_out');
});

test('re-opting in after an opt-out restores eligibility', function () {
    $r = Restaurant::create([
        'name' => 'M', 'subdomain' => 'marcos', 'email' => 'm@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);
    $user = consentCustomer();
    $service = app(MarketingConsentService::class);

    $service->optInEmail($user, $r, MarketingConsentSource::Account);
    $service->optOutEmail($user, $r, MarketingConsentSource::Account);
    $service->optInEmail($user, $r, MarketingConsentSource::Account);

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)->where('restaurant_id', $r->id)->first();
    expect($pivot->isEmailOptedIn())->toBeTrue()
        ->and($pivot->marketing_email_opted_out_at)->toBeNull();
    expect(MarketingConsentEvent::count())->toBe(3);
});

test('the profile page reports the current consent state', function () {
    $r = Restaurant::create([
        'name' => 'M', 'subdomain' => 'marcos', 'email' => 'm@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);
    $user = consentCustomer();

    $this->actingAs($user)
        ->get("http://{$r->subdomain}.plateful.test/account/profile")
        ->assertInertia(fn ($p) => $p->where('marketing.optedIn', false));

    app(MarketingConsentService::class)->optInEmail($user, $r, MarketingConsentSource::Account);

    $this->actingAs($user)
        ->get("http://{$r->subdomain}.plateful.test/account/profile")
        ->assertInertia(fn ($p) => $p->where('marketing.optedIn', true));
});
