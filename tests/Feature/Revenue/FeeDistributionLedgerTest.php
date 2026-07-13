<?php

use App\Enums\RevenueRole;
use App\Models\FeeDistribution;
use App\Models\PlatformRoleHolder;
use App\Models\User;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Storefront/CartTestHelpers.php';
require_once __DIR__.'/../Storefront/CheckoutTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['platform.revenue_shares' => ['founder' => 10, 'recruiter' => 0, 'overseer' => 90]]);
    Mail::fake();
});

test('paying for an order writes the fee distribution ledger for its roles', function () {
    $taylor = User::factory()->create();
    $ben = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);

    $f = cartFixture('marcos');
    $r = $f['restaurant'];
    $r->overseer_id = $ben->id;
    $r->save();

    $customer = User::create([
        'name' => 'Bob', 'email' => 'bob@example.test',
        'password' => Hash::make('password'), 'is_super_admin' => false,
    ]);

    $add = $this->actingAs($customer)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
            'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
        ]);
    $cookie = cartCookieFrom($add);

    fakeCheckoutSession();
    $this->actingAs($customer)
        ->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'Bob',
            'customer_email' => 'bob@example.test',
            'type' => 'pickup',
        ]);
    $order = payLatestCheckout();

    $fee = (int) $order->application_fee_cents;
    expect($fee)->toBeGreaterThan(0);

    $rows = FeeDistribution::where('order_id', $order->id)->get();

    // The whole retained fee is attributed, and Ben (overseer) gets the lion's share.
    expect((int) $rows->sum('amount_cents'))->toBe($fee);
    expect((int) $rows->where('user_id', $ben->id)->sum('amount_cents'))
        ->toBe((int) $rows->max('amount_cents'));
    expect((int) $rows->where('user_id', $taylor->id)->where('role', RevenueRole::Founder->value)->sum('amount_cents'))
        ->toBeGreaterThan(0);
});

test('replaying the webhook after the return does not double-write the ledger', function () {
    $taylor = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);

    $f = cartFixture('marcos');
    $r = $f['restaurant'];

    $customer = User::create([
        'name' => 'Cara', 'email' => 'cara@example.test',
        'password' => Hash::make('password'), 'is_super_admin' => false,
    ]);

    $add = $this->actingAs($customer)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
            'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
        ]);
    $cookie = cartCookieFrom($add);

    fakeCheckoutSession();
    $this->actingAs($customer)
        ->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'Cara',
            'customer_email' => 'cara@example.test',
            'type' => 'pickup',
        ]);

    $order = payLatestCheckout();
    $before = FeeDistribution::where('order_id', $order->id)->count();

    // Materialize again (idempotent webhook + return).
    payLatestCheckout();

    expect(FeeDistribution::where('order_id', $order->id)->count())->toBe($before);
});
