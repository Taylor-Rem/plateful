<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Exceptions\PosProviderException;
use App\Exceptions\PosTokenExpiredException;
use App\Jobs\PushOrderToPos;
use App\Models\OrderItem;
use App\Models\PosIntegration;
use App\Services\Pos\Clover\CloverPosProvider;
use App\Services\Pos\PosDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

beforeEach(function () {
    config()->set('platform.primary_domain', 'plateful.test');
    config()->set('services.clover', [
        'app_id' => 'sandbox-app-id',
        'app_secret' => 'sandbox-secret',
        'environment' => 'sandbox',
        'redirect' => 'https://admin.plateful.test/pos/clover/callback',
    ]);
});

/**
 * A 200 from Clover's atomic-order endpoint returning the given order id, plus
 * the tenders lookup and payment record that follow it.
 */
function fakeCloverOrderCreate(string $merchantId = 'MID_1', string $orderId = 'CL_ORDER_1'): void
{
    Http::fake([
        "apisandbox.dev.clover.com/v3/merchants/{$merchantId}/atomic_order/orders" => Http::response(['id' => $orderId]),
        "apisandbox.dev.clover.com/v3/merchants/{$merchantId}/tenders" => Http::response(cloverTenders()),
        "apisandbox.dev.clover.com/v3/merchants/{$merchantId}/orders/{$orderId}/payments" => Http::response(['id' => 'PAY_1', 'result' => 'SUCCESS']),
    ]);
}

/**
 * A merchant's tender list as Clover returns it, with the external tender.
 *
 * @return array{elements: list<array<string, mixed>>}
 */
function cloverTenders(bool $withExternal = true): array
{
    $elements = [
        ['id' => 'T_CASH', 'label' => 'Cash', 'labelKey' => 'com.clover.tender.cash', 'enabled' => true],
    ];

    if ($withExternal) {
        // Clover ships this tender disabled + hidden; the API still accepts it.
        $elements[] = ['id' => 'T_EXT', 'label' => 'External Payment', 'labelKey' => 'com.clover.tender.external_payment', 'enabled' => false, 'visible' => false];
    }

    return ['elements' => $elements];
}

function connectedCloverIntegration(int $restaurantId, string $merchantId = 'MID_1', array $overrides = []): PosIntegration
{
    return PosIntegration::withoutTenantScope()->create(array_merge([
        'restaurant_id' => $restaurantId,
        'provider' => PosProviderName::Clover,
        'external_merchant_id' => $merchantId,
        'location_id' => $merchantId,
        'access_token' => 'access-live',
        'refresh_token' => 'refresh-live',
        'token_expires_at' => now()->addMinutes(30),
        'status' => PosIntegrationStatus::Connected,
        'scopes' => ['ORDERS_WRITE'],
    ], $overrides));
}

// --- full pipeline (Clover faked at the HTTP boundary) ---------------------

it('pushes a paid order all the way to the Clover atomic-order API and stores the ticket id', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $order = makeOrder($restaurant);
    connectedCloverIntegration($restaurant->id, 'MID_42');

    fakeCloverOrderCreate('MID_42', 'CL_ORDER_42');

    (new PushOrderToPos($order->id))->handle(app(PosDispatcher::class));

    Http::assertSent(function ($request) use ($order) {
        $body = $request->data();

        return $request->url() === 'https://apisandbox.dev.clover.com/v3/merchants/MID_42/atomic_order/orders'
            && $request->hasHeader('Authorization', 'Bearer access-live')
            && $body['orderCart']['note'] === 'Plateful #'.$order->number
            && $body['orderCart']['lineItems'][0]['name'] === 'Sample item'
            && $body['orderCart']['lineItems'][0]['price'] === 1000;
    });

    $order->refresh();
    expect($order->pos_ticket_id)->toBe('CL_ORDER_42');
    expect($order->pos_provider)->toBe(PosProviderName::Clover);
    expect($order->pos_pushed_at)->not->toBeNull();
});

// --- external payment record -----------------------------------------------

it('records the Stripe payment on the ticket with the external tender so the register shows it paid', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $order = makeOrder($restaurant, ['subtotal_cents' => 1000, 'tax_cents' => 85, 'tip_cents' => 200, 'total_cents' => 1285]);
    $integration = connectedCloverIntegration($restaurant->id, 'MID_1');

    fakeCloverOrderCreate('MID_1', 'CL_ORDER_1');

    $result = app(CloverPosProvider::class)->pushOrder($order->load('items'), $integration);

    expect($result->success)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://apisandbox.dev.clover.com/v3/merchants/MID_1/tenders'
        && $request->hasHeader('Authorization', 'Bearer access-live'));

    Http::assertSent(function ($request) use ($order) {
        $body = $request->data();

        return $request->url() === 'https://apisandbox.dev.clover.com/v3/merchants/MID_1/orders/CL_ORDER_1/payments'
            && $body['tender']['id'] === 'T_EXT'
            && $body['amount'] === 1085
            && $body['taxAmount'] === 85
            && $body['tipAmount'] === 200
            && $body['externalPaymentId'] === $order->number;
    });
});

it('caches the external tender id per merchant across pushes', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $integration = connectedCloverIntegration($restaurant->id, 'MID_1');

    fakeCloverOrderCreate('MID_1', 'CL_ORDER_1');

    app(CloverPosProvider::class)->pushOrder(makeOrder($restaurant)->load('items'), $integration);
    app(CloverPosProvider::class)->pushOrder(makeOrder($restaurant)->load('items'), $integration);

    Http::assertSentCount(5); // 2 orders + 1 tenders lookup + 2 payments
    expect(Cache::get('clover.external_tender.MID_1'))->toBe('T_EXT');
});

it('leaves the ticket open but still succeeds when the merchant has no external tender', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $order = makeOrder($restaurant);
    $integration = connectedCloverIntegration($restaurant->id, 'MID_1');

    Http::fake([
        'apisandbox.dev.clover.com/v3/merchants/MID_1/atomic_order/orders' => Http::response(['id' => 'CL_ORDER_1']),
        'apisandbox.dev.clover.com/v3/merchants/MID_1/tenders' => Http::response(cloverTenders(withExternal: false)),
    ]);

    $result = app(CloverPosProvider::class)->pushOrder($order->load('items'), $integration);

    expect($result->success)->toBeTrue();
    expect($result->ticketId)->toBe('CL_ORDER_1');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/payments'));
});

it('still reports the push as successful when the payment record fails', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $order = makeOrder($restaurant);
    $integration = connectedCloverIntegration($restaurant->id, 'MID_1');

    Http::fake([
        'apisandbox.dev.clover.com/v3/merchants/MID_1/atomic_order/orders' => Http::response(['id' => 'CL_ORDER_1']),
        'apisandbox.dev.clover.com/v3/merchants/MID_1/tenders' => Http::response(cloverTenders()),
        'apisandbox.dev.clover.com/v3/merchants/MID_1/orders/CL_ORDER_1/payments' => Http::response(['message' => 'nope'], 400),
    ]);

    $result = app(CloverPosProvider::class)->pushOrder($order->load('items'), $integration);

    expect($result->success)->toBeTrue();
    expect($result->ticketId)->toBe('CL_ORDER_1');
});

it('expands quantity into repeated lines and folds options into a note', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $order = makeOrder($restaurant);
    $order->items()->delete();
    OrderItem::create([
        'order_id' => $order->id,
        'name' => 'Build-your-own Pizza',
        'unit_price_cents' => 1500,
        'quantity' => 2,
        'subtotal_cents' => 3000,
        'modifiers' => [
            'template_id' => 1,
            'template_name' => 'Pizza',
            'groups' => [
                ['group_id' => 1, 'group_name' => 'Size', 'selections' => [
                    ['option_id' => 1, 'option_name' => 'Large', 'price_delta_cents' => 300],
                ]],
                ['group_id' => 2, 'group_name' => 'Toppings', 'selections' => [
                    ['option_id' => 2, 'option_name' => 'Pepperoni', 'price_delta_cents' => 150],
                    ['option_id' => 3, 'option_name' => 'Mushroom', 'price_delta_cents' => 150],
                ]],
            ],
        ],
    ]);
    connectedCloverIntegration($restaurant->id, 'MID_1');

    fakeCloverOrderCreate('MID_1');

    (new PushOrderToPos($order->id))->handle(app(PosDispatcher::class));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/atomic_order/orders')) {
            return false;
        }

        $lines = $request->data()['orderCart']['lineItems'];

        // qty 2 → two identical lines Clover will group and count.
        return count($lines) === 2
            && $lines[0]['price'] === 1500
            && $lines[0]['note'] === 'Large, Pepperoni, Mushroom'
            && $lines[1] === $lines[0];
    });
});

// --- provider unit behavior ------------------------------------------------

it('raises a token-expired exception on a 401 from Clover', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $order = makeOrder($restaurant);
    $integration = connectedCloverIntegration($restaurant->id, 'MID_1');

    Http::fake([
        'apisandbox.dev.clover.com/v3/merchants/MID_1/atomic_order/orders' => Http::response(['message' => 'unauthorized'], 401),
    ]);

    app(CloverPosProvider::class)->pushOrder($order->load('items'), $integration);
})->throws(PosTokenExpiredException::class);

it('refreshes an expiring token before pushing, then persists the rotated pair', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $order = makeOrder($restaurant);
    $integration = connectedCloverIntegration($restaurant->id, 'MID_1', [
        'access_token' => 'access-stale',
        'token_expires_at' => now()->subMinute(),
    ]);

    Http::fake([
        'apisandbox.dev.clover.com/oauth/v2/refresh' => Http::response([
            'access_token' => 'access-refreshed',
            'access_token_expiration' => now()->addMinutes(30)->timestamp,
            'refresh_token' => 'refresh-rotated',
            'refresh_token_expiration' => now()->addDays(30)->timestamp,
        ]),
        'apisandbox.dev.clover.com/v3/merchants/MID_1/atomic_order/orders' => Http::response(['id' => 'CL_REFRESHED']),
    ]);

    $result = app(CloverPosProvider::class)->pushOrder($order->load('items'), $integration);

    expect($result->success)->toBeTrue();
    expect($result->ticketId)->toBe('CL_REFRESHED');

    // The order create used the freshly refreshed token...
    Http::assertSent(fn ($request) => str_contains($request->url(), '/atomic_order/orders')
        && $request->hasHeader('Authorization', 'Bearer access-refreshed'));

    // ...and the rotated single-use credentials were persisted.
    $integration->refresh();
    expect($integration->access_token)->toBe('access-refreshed');
    expect($integration->refresh_token)->toBe('refresh-rotated');
});

it('fails cleanly when the integration has no merchant id', function () {
    $restaurant = adminOrderRestaurant('clovershop');
    $order = makeOrder($restaurant);
    $integration = PosIntegration::factory()->make([
        'restaurant_id' => $restaurant->id,
        'provider' => PosProviderName::Clover,
        'external_merchant_id' => null,
    ]);

    Http::fake();

    expect(fn () => app(CloverPosProvider::class)->pushOrder($order->load('items'), $integration))
        ->toThrow(PosProviderException::class);

    Http::assertNothingSent();
});
