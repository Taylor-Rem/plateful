<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Exceptions\PosProviderException;
use App\Exceptions\PosTokenExpiredException;
use App\Jobs\PushOrderToPos;
use App\Models\OrderItem;
use App\Models\PosIntegration;
use App\Services\Pos\PosDispatcher;
use App\Services\Pos\Square\SquarePosProvider;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

beforeEach(function () {
    config()->set('platform.primary_domain', 'plateful.test');
    config()->set('services.square', [
        'application_id' => 'sandbox-app-id',
        'application_secret' => 'sandbox-secret',
        'environment' => 'sandbox',
        'redirect' => 'https://admin.plateful.test/pos/square/callback',
    ]);
});

/**
 * A 200 from Square's create-order endpoint returning the given order id.
 */
function fakeSquareOrderCreate(string $orderId = 'SQ_ORDER_1'): void
{
    Http::fake([
        'connect.squareupsandbox.com/v2/orders' => Http::response(['order' => ['id' => $orderId]]),
    ]);
}

// --- full pipeline (Square faked at the HTTP boundary) ---------------------

it('pushes a paid order all the way to the Square Orders API and stores the ticket id', function () {
    $restaurant = adminOrderRestaurant('squareshop');
    $order = makeOrder($restaurant);
    PosIntegration::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => PosProviderName::Square,
        'location_id' => 'L_MAIN',
        'external_merchant_id' => 'MERCHANT1',
        'access_token' => 'access-live',
        'refresh_token' => 'refresh-live',
        'token_expires_at' => now()->addDays(30),
        'status' => PosIntegrationStatus::Connected,
        'scopes' => ['ORDERS_WRITE'],
    ]);

    fakeSquareOrderCreate('SQ_ORDER_42');

    // Run the real queued job against the real container dispatcher — this is
    // the same path a live checkout takes, with only Square's HTTP faked.
    (new PushOrderToPos($order->id))->handle(app(PosDispatcher::class));

    Http::assertSent(function ($request) use ($order) {
        $body = $request->data();

        return $request->url() === 'https://connect.squareupsandbox.com/v2/orders'
            && $request->hasHeader('Authorization', 'Bearer access-live')
            && $body['idempotency_key'] === 'pf-order-'.$order->id
            && $body['order']['location_id'] === 'L_MAIN'
            && $body['order']['reference_id'] === $order->number
            && $body['order']['source']['name'] === 'Plateful'
            && $body['order']['line_items'][0]['name'] === 'Sample item'
            && $body['order']['line_items'][0]['quantity'] === '1'
            && $body['order']['line_items'][0]['base_price_money']['amount'] === 1000;
    });

    $order->refresh();
    expect($order->pos_ticket_id)->toBe('SQ_ORDER_42');
    expect($order->pos_provider)->toBe(PosProviderName::Square);
    expect($order->pos_pushed_at)->not->toBeNull();
});

it('folds selected options into a Square line-item note', function () {
    $restaurant = adminOrderRestaurant('squareshop');
    $order = makeOrder($restaurant);
    // Replace the plain fixture line with one carrying modifiers.
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
    PosIntegration::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => PosProviderName::Square,
        'location_id' => 'L_MAIN',
        'access_token' => 'access-live',
        'refresh_token' => 'refresh-live',
        'token_expires_at' => now()->addDays(30),
        'status' => PosIntegrationStatus::Connected,
    ]);

    fakeSquareOrderCreate();

    (new PushOrderToPos($order->id))->handle(app(PosDispatcher::class));

    Http::assertSent(function ($request) {
        $line = $request->data()['order']['line_items'][0];

        return $line['quantity'] === '2'
            && $line['base_price_money']['amount'] === 1500
            && $line['note'] === 'Large, Pepperoni, Mushroom';
    });
});

// --- provider unit behavior ------------------------------------------------

it('raises a token-expired exception on a 401 from Square', function () {
    $restaurant = adminOrderRestaurant('squareshop');
    $order = makeOrder($restaurant);
    $integration = PosIntegration::factory()->make([
        'restaurant_id' => $restaurant->id,
        'location_id' => 'L_MAIN',
        'access_token' => 'access-live',
        'token_expires_at' => now()->addDays(30),
    ]);

    Http::fake([
        'connect.squareupsandbox.com/v2/orders' => Http::response(['errors' => []], 401),
    ]);

    app(SquarePosProvider::class)->pushOrder($order->load('items'), $integration);
})->throws(PosTokenExpiredException::class);

it('refreshes an expiring token before pushing, then persists the new token', function () {
    $restaurant = adminOrderRestaurant('squareshop');
    $order = makeOrder($restaurant);
    $integration = PosIntegration::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => PosProviderName::Square,
        'location_id' => 'L_MAIN',
        'access_token' => 'access-stale',
        'refresh_token' => 'refresh-live',
        'token_expires_at' => now()->subMinute(),
        'status' => PosIntegrationStatus::Connected,
    ]);

    Http::fake([
        'connect.squareupsandbox.com/oauth2/token' => Http::response([
            'access_token' => 'access-refreshed',
            'refresh_token' => 'refresh-rotated',
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'merchant_id' => 'MERCHANT1',
        ]),
        'connect.squareupsandbox.com/v2/orders' => Http::response(['order' => ['id' => 'SQ_REFRESHED']]),
    ]);

    $result = app(SquarePosProvider::class)->pushOrder($order->load('items'), $integration);

    expect($result->success)->toBeTrue();
    expect($result->ticketId)->toBe('SQ_REFRESHED');

    // The order create used the freshly refreshed token...
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/orders')
        && $request->hasHeader('Authorization', 'Bearer access-refreshed'));

    // ...and the rotated credentials were persisted.
    $integration->refresh();
    expect($integration->access_token)->toBe('access-refreshed');
    expect($integration->refresh_token)->toBe('refresh-rotated');
});

it('fails cleanly when the integration has no location id', function () {
    $restaurant = adminOrderRestaurant('squareshop');
    $order = makeOrder($restaurant);
    $integration = PosIntegration::factory()->make([
        'restaurant_id' => $restaurant->id,
        'location_id' => null,
    ]);

    Http::fake();

    expect(fn () => app(SquarePosProvider::class)->pushOrder($order->load('items'), $integration))
        ->toThrow(PosProviderException::class);

    Http::assertNothingSent();
});
