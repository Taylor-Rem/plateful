<?php

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Stripe\StripeConnectService;
use Mockery\MockInterface;
use Stripe\Account;
use Stripe\Service\AccountService;
use Stripe\StripeClient;

const STRIPE_ADMIN = 'http://admin.plateful.test';

beforeEach(function () {
    config()->set('services.stripe.secret', 'sk_test_dummy');
    config()->set('services.stripe.webhook_secret', 'whsec_test_dummy');
});

/**
 * @return array{0: User, 1: Restaurant}
 */
function stripeOwnerAndRestaurant(array $overrides = []): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->approved()->create(array_merge([
        'subdomain' => 'pizzajoint',
        'is_active' => true,
    ], $overrides));
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

/**
 * Partial mock: real syncAccountStatus/statusFor, mocked API-hitting methods.
 */
function mockConnect(): MockInterface
{
    $mock = Mockery::mock(
        StripeConnectService::class.'[createExpressAccount,createAccountLink,retrieveAccount,createDashboardLink]',
        [app(StripeClient::class)]
    );
    app()->instance(StripeConnectService::class, $mock);

    return $mock;
}

// --- Stripe-Notice suppression --------------------------------------------

it('does not fail when a Stripe SDK call emits a Stripe-Notice warning', function () {
    [, $restaurant] = stripeOwnerAndRestaurant();

    $accounts = Mockery::mock(AccountService::class);
    $accounts->shouldReceive('create')->once()->andReturnUsing(function () {
        // stripe-php promotes a `Stripe-Notice` response header into this
        // E_USER_WARNING, which Laravel turns into a fatal ErrorException.
        trigger_error('We recommend building your integration using Accounts v2', E_USER_WARNING);

        return Account::constructFrom(['id' => 'acct_notice']);
    });

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->shouldReceive('getService')->with('accounts')->andReturn($accounts);

    $service = new StripeConnectService($stripe);

    expect($service->createExpressAccount($restaurant))->toBe('acct_notice');
    expect($restaurant->fresh()->stripe_account_id)->toBe('acct_notice');
});

it('leaves E_USER_WARNINGs raised outside a Stripe call fatal', function () {
    [, $restaurant] = stripeOwnerAndRestaurant();

    $accounts = Mockery::mock(AccountService::class);
    $accounts->shouldReceive('create')->once()
        ->andReturn(Account::constructFrom(['id' => 'acct_scoped']));

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->shouldReceive('getService')->with('accounts')->andReturn($accounts);

    $service = new StripeConnectService($stripe);

    // The wrapped call must restore the prior handler, so a warning afterwards
    // still becomes a fatal ErrorException.
    $service->createExpressAccount($restaurant);

    expect(fn () => trigger_error('not a stripe notice', E_USER_WARNING))
        ->toThrow(ErrorException::class);
});

// --- statusFor mapping ----------------------------------------------------

it('maps Stripe readiness flags to the status vocabulary', function () {
    expect(StripeConnectService::statusFor(true, true))->toBe(Restaurant::STRIPE_ENABLED)
        ->and(StripeConnectService::statusFor(true, false))->toBe(Restaurant::STRIPE_ENABLED)
        ->and(StripeConnectService::statusFor(false, true))->toBe(Restaurant::STRIPE_RESTRICTED)
        ->and(StripeConnectService::statusFor(false, false))->toBe(Restaurant::STRIPE_PENDING);
});

// --- start ----------------------------------------------------------------

it('creates a connected account and sends the owner to the onboarding link', function () {
    [$owner, $restaurant] = stripeOwnerAndRestaurant();

    $mock = mockConnect();
    $mock->shouldReceive('createExpressAccount')->once()
        ->andReturnUsing(function (Restaurant $r) {
            $r->forceFill([
                'stripe_account_id' => 'acct_123',
                'stripe_account_status' => Restaurant::STRIPE_PENDING,
            ])->save();

            return 'acct_123';
        });
    $mock->shouldReceive('createAccountLink')->once()->andReturn('https://connect.stripe.test/setup');

    // Inertia (XHR) requests can't follow an external 302; Inertia::location
    // answers with a 409 + X-Inertia-Location so the client does a full visit.
    $this->actingAs($owner)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding/stripe/connect")
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://connect.stripe.test/setup');

    expect($restaurant->fresh()->stripe_account_id)->toBe('acct_123');
});

it('does not recreate the account when one already exists', function () {
    [$owner, $restaurant] = stripeOwnerAndRestaurant();
    $restaurant->forceFill(['stripe_account_id' => 'acct_existing'])->save();

    $mock = mockConnect();
    $mock->shouldReceive('createExpressAccount')->never();
    $mock->shouldReceive('createAccountLink')->once()->andReturn('https://connect.stripe.test/again');

    $this->actingAs($owner)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding/stripe/connect")
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://connect.stripe.test/again');
});

it('hands back a fresh onboarding link on refresh', function () {
    [$owner, $restaurant] = stripeOwnerAndRestaurant();
    $restaurant->forceFill(['stripe_account_id' => 'acct_refresh'])->save();

    $mock = mockConnect();
    $mock->shouldReceive('createExpressAccount')->never();
    $mock->shouldReceive('createAccountLink')->once()->andReturn('https://connect.stripe.test/refresh');

    // refresh is reached via Stripe's full-page redirect (not an Inertia XHR),
    // so Inertia::location answers with a plain 302 to the fresh link.
    $this->actingAs($owner)
        ->get(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding/stripe/refresh")
        ->assertRedirect('https://connect.stripe.test/refresh');
});

it('blocks staff from starting Stripe onboarding', function () {
    [, $restaurant] = stripeOwnerAndRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->post(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding/stripe/connect")
        ->assertForbidden();
});

// --- return ---------------------------------------------------------------

it('syncs status from Stripe on return and lands back on onboarding', function () {
    [$owner, $restaurant] = stripeOwnerAndRestaurant();
    $restaurant->forceFill([
        'stripe_account_id' => 'acct_123',
        'stripe_account_status' => Restaurant::STRIPE_PENDING,
    ])->save();

    $mock = mockConnect();
    $mock->shouldReceive('retrieveAccount')->once()->with('acct_123')
        ->andReturn(Account::constructFrom([
            'id' => 'acct_123',
            'charges_enabled' => true,
            'details_submitted' => true,
        ]));

    $this->actingAs($owner)
        ->get(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding/stripe/return")
        ->assertRedirect(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding");

    expect($restaurant->fresh()->isStripeReady())->toBeTrue();
});

// --- dashboard ------------------------------------------------------------

it('redirects to the Express dashboard when an account exists', function () {
    [$owner, $restaurant] = stripeOwnerAndRestaurant();
    $restaurant->forceFill(['stripe_account_id' => 'acct_123'])->save();

    $mock = mockConnect();
    $mock->shouldReceive('createDashboardLink')->once()->andReturn('https://dashboard.stripe.test/login');

    $this->actingAs($owner)
        ->get(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding/stripe/dashboard")
        ->assertRedirect('https://dashboard.stripe.test/login');
});

// --- webhook --------------------------------------------------------------

it('updates account status from an account.updated webhook', function () {
    $restaurant = Restaurant::factory()->approved()->create();
    $restaurant->forceFill([
        'stripe_account_id' => 'acct_hook',
        'stripe_account_status' => Restaurant::STRIPE_PENDING,
    ])->save();

    $payload = json_encode([
        'id' => 'evt_1',
        'object' => 'event',
        'type' => 'account.updated',
        'data' => ['object' => [
            'id' => 'acct_hook',
            'object' => 'account',
            'charges_enabled' => true,
            'details_submitted' => true,
        ]],
    ]);

    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_dummy');

    $this->call(
        'POST',
        STRIPE_ADMIN.'/stripe/webhook',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertOk();

    expect($restaurant->fresh()->stripe_account_status)->toBe(Restaurant::STRIPE_ENABLED);
});

it('rejects a webhook with a bad signature', function () {
    $payload = json_encode(['id' => 'evt_1', 'type' => 'account.updated', 'data' => ['object' => []]]);

    $this->call(
        'POST',
        STRIPE_ADMIN.'/stripe/webhook',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertStatus(400);
});
