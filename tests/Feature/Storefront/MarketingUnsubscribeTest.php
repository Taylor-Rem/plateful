<?php

use App\Enums\MarketingConsentSource;
use App\Models\MarketingConsentEvent;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use App\Services\MarketingConsentService;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function unsubRestaurant(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => "R-{$sub}", 'subdomain' => $sub, 'email' => "hi@{$sub}.test",
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);
}

function unsubUser(Restaurant $r, string $email = 'alice@example.test'): User
{
    $user = User::create([
        'name' => 'Alice', 'email' => $email,
        'password' => Hash::make('password'), 'is_super_admin' => false,
    ]);

    app(MarketingConsentService::class)->optInEmail($user, $r, MarketingConsentSource::Checkout);

    return $user;
}

/**
 * The signed unsubscribe link rewritten onto the test-reachable http host.
 */
function unsubscribeUrlFor(User $user, Restaurant $r): string
{
    $url = app(MarketingConsentService::class)->unsubscribeUrl($user, $r);

    return str_replace('https://', 'http://', $url);
}

test('a signed unsubscribe link opts the customer out without logging in', function () {
    $r = unsubRestaurant();
    $user = unsubUser($r);

    $this->get(unsubscribeUrlFor($user, $r))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Storefront/MarketingUnsubscribed')
            ->where('email', $user->email));

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)->where('restaurant_id', $r->id)->first();
    expect($pivot->isEmailOptedIn())->toBeFalse();

    $event = MarketingConsentEvent::query()->latest('id')->first();
    expect($event->action->value)->toBe('opted_out')
        ->and($event->source)->toBe(MarketingConsentSource::UnsubscribeLink);
});

test('clicking the link twice is harmless', function () {
    $r = unsubRestaurant();
    $user = unsubUser($r);
    $url = unsubscribeUrlFor($user, $r);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();

    // One opt-in from setup + exactly one opt-out.
    expect(MarketingConsentEvent::count())->toBe(2);
});

test('a tampered signature is rejected', function () {
    $r = unsubRestaurant();
    $user = unsubUser($r);

    $tampered = str_replace("user={$user->id}", 'user='.($user->id + 1), unsubscribeUrlFor($user, $r));

    $this->get($tampered)->assertForbidden();

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)->where('restaurant_id', $r->id)->first();
    expect($pivot->isEmailOptedIn())->toBeTrue();
});

test('an unsigned request is rejected', function () {
    $r = unsubRestaurant();
    $user = unsubUser($r);

    $this->get("http://{$r->subdomain}.plateful.test/marketing/unsubscribe?user={$user->id}&restaurant={$r->id}")
        ->assertForbidden();
});

test('a link issued for one restaurant does not work on another storefront', function () {
    $marcos = unsubRestaurant('marcos');
    $bobs = unsubRestaurant('bobs');
    $user = unsubUser($marcos);

    // Same signed path replayed on the wrong host: the restaurant id inside
    // the signature doesn't match the tenant, so nothing is opted out.
    $replayed = str_replace('marcos.plateful.test', 'bobs.plateful.test', unsubscribeUrlFor($user, $marcos));

    $this->get($replayed)->assertNotFound();

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)->where('restaurant_id', $marcos->id)->first();
    expect($pivot->isEmailOptedIn())->toBeTrue();
});

test('the undo button resubscribes', function () {
    $r = unsubRestaurant();
    $user = unsubUser($r);

    $response = $this->get(unsubscribeUrlFor($user, $r));

    $resubscribePath = $response->viewData('page')['props']['resubscribeUrl'] ?? null;
    expect($resubscribePath)->not->toBeNull();

    $this->post("http://{$r->subdomain}.plateful.test".$resubscribePath)
        ->assertRedirect();

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)->where('restaurant_id', $r->id)->first();
    expect($pivot->isEmailOptedIn())->toBeTrue();

    $event = MarketingConsentEvent::query()->latest('id')->first();
    expect($event->action->value)->toBe('opted_in')
        ->and($event->source)->toBe(MarketingConsentSource::UnsubscribeLink);
});
