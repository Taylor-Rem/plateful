<?php

use App\Enums\DeliveryFeeStrategy;
use App\Enums\DeliveryMode;
use App\Enums\PaymentState;
use App\Jobs\ExpireAuthorizedDelivery;
use App\Models\DeliveryIntegration;
use App\Models\Order;
use App\Models\PendingCheckout;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\CartManager;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Stripe\PaymentIntent;

require_once __DIR__.'/ApiCheckoutHelpers.php';

beforeEach(function () {
    Mail::fake();
});

describe('intents', function () {
    test('a pickup checkout snapshots the cart and returns a PaymentSheet-ready intent', function () {
        $f = cartFixture();
        $r = $f['restaurant'];
        $r->update(['tax_rate_percent' => 8.875]);
        [, $token] = addPepViaApi($f);

        $mock = fakePaymentIntents();
        $mock->shouldReceive('createPaymentIntent')->once()->withArgs(
            fn (Restaurant $restaurant, int $total, int $fee, string $email, string $idempotencyKey, int $pendingId, bool $manualCapture) => $restaurant->is($r)
                && $total === 1400 + 124 + 500   // subtotal + 8.875% tax + $5 tip
                && $fee === 56                    // 4% of the food subtotal only
                && $email === 'ada@example.com'
                && $idempotencyKey === 'pending_checkout_'.$pendingId
                && $manualCapture === false,
        )->andReturn(PaymentIntent::constructFrom(['id' => 'pi_app_1', 'client_secret' => 'pi_app_1_secret', 'status' => 'requires_payment_method']));

        $response = $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($r).'/checkout/intents', pickupCheckoutPayload(['tip_cents' => 500]));

        $response->assertCreated()
            ->assertJsonPath('data.paymentIntentId', 'pi_app_1')
            ->assertJsonPath('data.clientSecret', 'pi_app_1_secret')
            ->assertJsonPath('data.publishableKey', 'pk_test_dummy')
            ->assertJsonPath('data.stripeAccountId', 'acct_fixture')
            ->assertJsonPath('data.manualCapture', false)
            ->assertJsonPath('data.subtotalCents', 1400)
            ->assertJsonPath('data.taxCents', 124)
            ->assertJsonPath('data.tipCents', 500)
            ->assertJsonPath('data.totalCents', 2024);

        $pending = PendingCheckout::query()->findOrFail($response->json('data.pendingCheckoutId'));
        expect($pending->stripe_payment_intent_id)->toBe('pi_app_1')
            ->and($pending->status)->toBe(PendingCheckout::STATUS_AWAITING)
            ->and($pending->payload['application_fee_cents'])->toBe(56)
            ->and(Order::count())->toBe(0);
    });

    test('the app and the web produce the same snapshot for the same cart', function () {
        $f = cartFixture();
        $r = $f['restaurant'];
        $r->update(['tax_rate_percent' => 7.25]);
        [, $token] = addPepViaApi($f);
        fakePaymentIntents();

        $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($r).'/checkout/intents', pickupCheckoutPayload(['tip_cents' => 210]))
            ->assertCreated();

        $api = PendingCheckout::query()->latest('id')->firstOrFail()->payload;

        // The web path: same cart token via cookie, same tip as a preset (15% of $14 = 210).
        require_once __DIR__.'/../../Storefront/CheckoutTestHelpers.php';
        fakeCheckoutSession();
        $this->withCookie(CartManager::COOKIE_NAME, $token)
            ->post("http://{$r->subdomain}.plateful.test/orders", pickupCheckoutPayload(['tip_preset' => '15']));

        $web = PendingCheckout::query()->latest('id')->firstOrFail()->payload;

        foreach (['subtotal_cents', 'tax_cents', 'tip_cents', 'delivery_fee_cents', 'application_fee_cents', 'platform_commission_cents', 'total_cents', 'items'] as $key) {
            expect($api[$key])->toEqual($web[$key], $key);
        }
    });

    test('courier delivery holds the card instead of charging it', function () {
        config(['services.uber_direct.client_id' => 'cid', 'services.uber_direct.client_secret' => 'sec']);
        $f = cartFixture();
        $r = $f['restaurant'];
        $r->update([
            'delivery_enabled' => true,
            'delivery_mode' => DeliveryMode::ThirdParty,
            'delivery_provider_priority' => ['uber'],
            'delivery_fee_strategy' => DeliveryFeeStrategy::PassThrough,
            'tax_rate_percent' => 0,
            'phone' => '5551234567',
        ]);
        DeliveryIntegration::factory()->create(['restaurant_id' => $r->id, 'customer_id' => 'cust_quote']);
        [, $token] = addPepViaApi($f);

        Http::fake([
            UberDirectTokenService::TOKEN_URL => Http::response(['access_token' => 't', 'expires_in' => 2592000]),
            'api.uber.com/*' => Http::response([
                'id' => 'dqt_1', 'fee' => 799, 'duration' => 44, 'pickup_duration' => 18,
                'expires' => now()->addMinutes(15)->toIso8601String(),
                'dropoff_eta' => now()->addMinutes(44)->toIso8601String(),
            ]),
        ]);
        $address = ['street' => '285 Fulton St', 'city' => 'New York', 'state' => 'NY', 'postal_code' => '10006', 'country' => 'US'];

        $quote = $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($r).'/checkout/delivery-quote', ['address' => $address])
            ->assertOk()
            ->assertJsonStructure(['quote' => ['token', 'feeCents', 'etaMinutes', 'expiresAt']])
            ->json('quote');

        $mock = fakePaymentIntents();
        $mock->shouldReceive('createPaymentIntent')->once()
            ->withArgs(fn ($restaurant, $total, $fee, $email, $key, $pendingId, $manualCapture) => $manualCapture === true && $total === 1400 + $quote['feeCents'])
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_hold', 'client_secret' => 's', 'status' => 'requires_payment_method']));

        $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($r).'/checkout/intents', pickupCheckoutPayload([
                'type' => 'delivery',
                'customer_phone' => '5550001111',
                'delivery_address' => $address,
                'delivery_quote_token' => $quote['token'],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.manualCapture', true)
            ->assertJsonPath('data.deliveryFeeCents', $quote['feeCents']);
    });

    test('a restaurant that cannot take payments is refused', function () {
        $f = cartFixture();
        $r = $f['restaurant'];
        $r->forceFill(['stripe_account_status' => Restaurant::STRIPE_PENDING])->save();
        [, $token] = addPepViaApi($f);
        fakePaymentIntents();

        $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($r).'/checkout/intents', pickupCheckoutPayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['payment']);

        expect(PendingCheckout::count())->toBe(0);
    });

    test('an empty or missing cart cannot start a checkout', function () {
        $f = cartFixture();
        fakePaymentIntents();

        $this->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload())
            ->assertForbidden();
    });

    test('a missing publishable key is a clear 503, not a half-created checkout', function () {
        $f = cartFixture();
        [, $token] = addPepViaApi($f);
        fakePaymentIntents();
        config()->set('services.stripe.key', '');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload())
            ->assertStatus(503);

        expect(PendingCheckout::count())->toBe(0);
    });

    test('a signed-in customer\'s checkout is bound to their account', function () {
        $f = cartFixture();
        $user = User::factory()->create();
        $bearer = apiTokenFor($user);
        fakePaymentIntents();

        $this->withToken($bearer)->postJson(apiRestaurantBase($f['restaurant']).'/cart/items/'.$f['simple']->id)->assertCreated();
        forgetApiGuards();

        $this->withToken($bearer)
            ->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload(['marketing_opt_in' => true]))
            ->assertCreated();

        $pending = PendingCheckout::query()->latest('id')->firstOrFail();
        expect($pending->user_id)->toBe($user->id)
            ->and($pending->payload['marketing_opt_in'])->toBeTrue();
    });
});

describe('confirm', function () {
    /**
     * @return array{0: array<string, mixed>, 1: string, 2: int}
     */
    function startedCheckout(string $retrievedStatus = 'succeeded'): array
    {
        $f = cartFixture();
        [, $token] = addPepViaApi($f);
        fakePaymentIntents($retrievedStatus);

        $pendingId = test()->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload())
            ->assertCreated()
            ->json('data.pendingCheckoutId');
        test()->flushHeaders();

        return [$f, $token, $pendingId];
    }

    test('a succeeded intent materialises a captured order and empties the cart', function () {
        [$f, $token, $pendingId] = startedCheckout('succeeded');

        $response = $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm");

        $response->assertCreated()
            ->assertJsonPath('data.order.status', 'pending')
            ->assertJsonPath('data.order.totalCents', 1400)
            ->assertJsonPath('data.order.items.0.name', 'Pep')
            ->assertJsonStructure(['data' => ['order' => ['number', 'items', 'delivery'], 'confirmationToken']]);

        $order = Order::firstOrFail();
        expect($order->stripe_payment_intent_id)->toBe('pi_app_1')
            ->and($order->payment_state)->toBe(PaymentState::Captured)
            ->and($order->stripe_checkout_session_id)->toBeNull()
            ->and($response->json('data.confirmationToken'))->toBe($order->confirmation_token)
            ->and(PendingCheckout::findOrFail($pendingId)->status)->toBe(PendingCheckout::STATUS_CONSUMED);

        $this->withHeader('X-Cart-Token', $token)->getJson(apiRestaurantBase($f['restaurant']).'/cart')
            ->assertJsonPath('data.itemCount', 0);
    });

    test('a held intent materialises an authorized order and arms the courier deadline', function () {
        [$f, $token, $pendingId] = startedCheckout('requires_capture');
        Queue::fake();

        $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm")
            ->assertCreated();

        expect(Order::firstOrFail()->payment_state)->toBe(PaymentState::Authorized);
        Queue::assertPushed(ExpireAuthorizedDelivery::class);
    });

    test('an intent that has not been paid is a 409 and creates nothing', function () {
        [$f, $token, $pendingId] = startedCheckout('requires_payment_method');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('paymentStatus', 'requires_payment_method');

        expect(Order::count())->toBe(0)
            ->and(PendingCheckout::findOrFail($pendingId)->status)->toBe(PendingCheckout::STATUS_AWAITING);
    });

    test('confirming twice returns the same order once', function () {
        [$f, $token, $pendingId] = startedCheckout('succeeded');
        $url = apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm";

        $first = $this->withHeader('X-Cart-Token', $token)->postJson($url)->assertCreated()->json('data.order.number');
        $second = $this->withHeader('X-Cart-Token', $token)->postJson($url)->assertOk()->json('data.order.number');

        expect($second)->toBe($first)->and(Order::count())->toBe(1);
    });

    test('someone without the cart token cannot confirm or read a guest checkout', function () {
        [$f, , $pendingId] = startedCheckout('succeeded');
        $url = apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm";

        $this->postJson($url)->assertNotFound();
        $this->withHeader('X-Cart-Token', 'not-the-token')->postJson($url)->assertNotFound();
        forgetApiGuards();
        $this->withToken(apiTokenFor(User::factory()->create()))->postJson($url)->assertNotFound();

        expect(Order::count())->toBe(0);
    });

    test('a signed-in customer\'s checkout can only be confirmed by them', function () {
        $f = cartFixture();
        $owner = User::factory()->create();
        $bearer = apiTokenFor($owner);
        fakePaymentIntents('succeeded');

        $this->withToken($bearer)->postJson(apiRestaurantBase($f['restaurant']).'/cart/items/'.$f['simple']->id)->assertCreated();
        forgetApiGuards();
        $pendingId = $this->withToken($bearer)
            ->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload())
            ->assertCreated()->json('data.pendingCheckoutId');
        $url = apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm";

        forgetApiGuards();
        $this->withToken(apiTokenFor(User::factory()->create()))->postJson($url)->assertNotFound();
        forgetApiGuards();
        $this->postJson($url)->assertNotFound();
        forgetApiGuards();
        $this->withToken($bearer)->postJson($url)->assertCreated()->assertJsonPath('data.order.customerName', 'Ada Diner');

        expect(Order::firstOrFail()->user_id)->toBe($owner->id);
    });
});

describe('webhook', function () {
    test('payment_intent.succeeded materialises the order and a later confirm returns it', function () {
        $f = cartFixture();
        [, $token] = addPepViaApi($f);
        fakePaymentIntents('succeeded');
        $pendingId = $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload())
            ->json('data.pendingCheckoutId');
        $this->flushHeaders();

        postStripeEvent('payment_intent.succeeded', ['id' => 'pi_app_1', 'object' => 'payment_intent', 'status' => 'succeeded'])->assertOk();

        $order = Order::firstOrFail();
        expect($order->payment_state)->toBe(PaymentState::Captured)
            ->and($order->stripe_payment_intent_id)->toBe('pi_app_1')
            ->and(PendingCheckout::findOrFail($pendingId)->status)->toBe(PendingCheckout::STATUS_CONSUMED);

        // Duplicate delivery + the app's own confirm: still one order.
        postStripeEvent('payment_intent.succeeded', ['id' => 'pi_app_1', 'object' => 'payment_intent', 'status' => 'succeeded'])->assertOk();
        $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm")
            ->assertOk()->assertJsonPath('data.order.number', $order->number);

        expect(Order::count())->toBe(1);
    });

    test('payment_intent.amount_capturable_updated materialises an authorized order', function () {
        $f = cartFixture();
        [, $token] = addPepViaApi($f);
        fakePaymentIntents('requires_capture');
        $this->withHeader('X-Cart-Token', $token)
            ->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload())
            ->assertCreated();
        $this->flushHeaders();
        Queue::fake();

        postStripeEvent('payment_intent.amount_capturable_updated', ['id' => 'pi_app_1', 'object' => 'payment_intent', 'status' => 'requires_capture'])->assertOk();

        expect(Order::firstOrFail()->payment_state)->toBe(PaymentState::Authorized);
    });

    test('an intent no pending checkout was issued for is acknowledged and ignored', function () {
        cartFixture();

        postStripeEvent('payment_intent.succeeded', ['id' => 'pi_web_or_unknown', 'object' => 'payment_intent', 'status' => 'succeeded'])->assertOk();

        expect(Order::count())->toBe(0);
    });
});
