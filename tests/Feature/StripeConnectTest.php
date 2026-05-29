<?php

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Stripe\StripeConnectService;
use Mockery\MockInterface;
use Stripe\Account;
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

// --- statusFor mapping ----------------------------------------------------

it('maps Stripe readiness flags to the status vocabulary', function () {
    expect(StripeConnectService::statusFor(true, true))->toBe(Restaurant::STRIPE_ENABLED)
        ->and(StripeConnectService::statusFor(true, false))->toBe(Restaurant::STRIPE_ENABLED)
        ->and(StripeConnectService::statusFor(false, true))->toBe(Restaurant::STRIPE_RESTRICTED)
        ->and(StripeConnectService::statusFor(false, false))->toBe(Restaurant::STRIPE_PENDING);
});

// --- start ----------------------------------------------------------------

it('creates a connected account and redirects to the onboarding link', function () {
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

    $this->actingAs($owner)
        ->post(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding/stripe/connect")
        ->assertRedirect('https://connect.stripe.test/setup');

    expect($restaurant->fresh()->stripe_account_id)->toBe('acct_123');
});

it('does not recreate the account when one already exists', function () {
    [$owner, $restaurant] = stripeOwnerAndRestaurant();
    $restaurant->forceFill(['stripe_account_id' => 'acct_existing'])->save();

    $mock = mockConnect();
    $mock->shouldReceive('createExpressAccount')->never();
    $mock->shouldReceive('createAccountLink')->once()->andReturn('https://connect.stripe.test/again');

    $this->actingAs($owner)
        ->post(STRIPE_ADMIN."/{$restaurant->subdomain}/onboarding/stripe/connect")
        ->assertRedirect('https://connect.stripe.test/again');
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
