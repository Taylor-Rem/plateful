<?php

use App\Contracts\DeliveryProvider;
use App\Enums\DeliveryFallbackAction;
use App\Enums\DeliveryMode;
use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\OrderType;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryCancellation;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DeliveryQuote;
use App\Services\Delivery\DeliveryQuoteRequest;
use App\Services\Delivery\SelfDeliveryProvider;
use Illuminate\Support\Str;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

function makeDeliveryOrder(Restaurant $r): Order
{
    return makeOrder($r, [
        'type' => OrderType::Delivery,
        'delivery_address' => [
            'street' => '1 Pine',
            'city' => 'NYC',
            'state' => 'NY',
            'postal_code' => '10002',
            'country' => 'US',
        ],
        'number' => Str::upper(Str::random(8)),
    ]);
}

function fakeProvider(DeliveryProviderName $name, bool $supports = true, ?Throwable $throwOnQuote = null): DeliveryProvider
{
    return new class($name, $supports, $throwOnQuote) implements DeliveryProvider
    {
        public function __construct(
            private DeliveryProviderName $n,
            private bool $supports,
            private ?Throwable $throwOnQuote,
        ) {}

        public function name(): DeliveryProviderName
        {
            return $this->n;
        }

        public function supports(Restaurant $r): bool
        {
            return $this->supports;
        }

        public function quote(DeliveryQuoteRequest $req): DeliveryQuote
        {
            if ($this->throwOnQuote) {
                throw $this->throwOnQuote;
            }

            return new DeliveryQuote(provider: $this->n, feeCents: 999, etaMinutes: 30);
        }

        public function create(Order $order, DeliveryQuote $quote): DeliveryAssignment
        {
            return DeliveryAssignment::create([
                'order_id' => $order->id,
                'provider' => $this->n,
                'status' => DeliveryStatus::Pending,
                'quote_fee_cents' => $quote->feeCents,
            ]);
        }

        public function status(DeliveryAssignment $a): DeliveryAssignment
        {
            return $a;
        }

        public function cancel(DeliveryAssignment $a): DeliveryCancellation
        {
            return DeliveryCancellation::fullyRefunded();
        }
    };
}

it('returns empty chain when delivery is disabled', function () {
    $r = adminOrderRestaurant('disabled');
    $dispatcher = new DeliveryDispatcher([]);

    expect($dispatcher->providerChainFor($r))->toBe([]);
});

it('returns self when restaurant is in self-delivery mode', function () {
    $r = adminOrderRestaurant('selfco');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery,
    ]);

    $dispatcher = new DeliveryDispatcher([]);

    expect($dispatcher->providerChainFor($r))->toBe([DeliveryProviderName::Self]);
});

it('returns the configured priority for third-party mode', function () {
    $r = adminOrderRestaurant('tpco');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => ['uber', 'doordash'],
    ]);

    $dispatcher = new DeliveryDispatcher([]);

    expect($dispatcher->providerChainFor($r))->toBe([
        DeliveryProviderName::Uber,
        DeliveryProviderName::DoorDash,
    ]);
});

it('defaults the chain to both courier networks when no priority is set', function () {
    // One-click enabling EITHER network must make delivery work without
    // anyone hand-editing delivery_provider_priority: providerFor() skips the
    // unconnected one at quote time, so listing both here is what wires the
    // enable button to actual dispatch.
    $r = adminOrderRestaurant('defaultchain');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => null,
    ]);

    $dispatcher = new DeliveryDispatcher([]);

    expect($dispatcher->providerChainFor($r))->toBe([
        DeliveryProviderName::DoorDash,
        DeliveryProviderName::Uber,
    ]);
});

it('self-delivery provider quotes the restaurant flat fee', function () {
    $r = adminOrderRestaurant('flat');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery,
        'delivery_fee_cents' => 599,
    ]);

    $quote = (new SelfDeliveryProvider)->quote(new DeliveryQuoteRequest(
        restaurant: $r,
        dropoffAddress: ['street' => '1 Main', 'city' => 'NYC', 'state' => 'NY', 'postal_code' => '10001'],
        subtotalCents: 2000,
        tipCents: 500,
    ));

    expect($quote->feeCents)->toBe(599)
        ->and($quote->provider)->toBe(DeliveryProviderName::Self);
});

it('dispatches successfully via self-delivery and attaches assignment to order', function () {
    $r = adminOrderRestaurant('happy');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery,
        'delivery_fee_cents' => 500,
    ]);
    $order = makeDeliveryOrder($r);

    $dispatcher = new DeliveryDispatcher([
        DeliveryProviderName::Self->value => new SelfDeliveryProvider,
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeTrue()
        ->and($result->provider)->toBe(DeliveryProviderName::Self)
        ->and($result->assignment)->not->toBeNull();
    expect($order->fresh()->delivery_assignment_id)->toBe($result->assignment->id);
});

it('falls back to the next provider when the first fails and fallback is try_next_provider', function () {
    $r = adminOrderRestaurant('fallback');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => ['doordash', 'uber'],
        'delivery_fallback_action' => DeliveryFallbackAction::TryNextProvider,
    ]);
    $order = makeDeliveryOrder($r);

    $dispatcher = new DeliveryDispatcher([
        DeliveryProviderName::DoorDash->value => fakeProvider(
            DeliveryProviderName::DoorDash,
            throwOnQuote: new RuntimeException('no driver'),
        ),
        DeliveryProviderName::Uber->value => fakeProvider(DeliveryProviderName::Uber),
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeTrue()
        ->and($result->provider)->toBe(DeliveryProviderName::Uber)
        ->and($result->attemptedProviders)->toBe(['doordash', 'uber']);
});

it('stops at first failure when fallback action is not try_next_provider', function () {
    $r = adminOrderRestaurant('holdco');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => ['doordash', 'uber'],
        'delivery_fallback_action' => DeliveryFallbackAction::HoldForOwner,
    ]);
    $order = makeDeliveryOrder($r);

    $dispatcher = new DeliveryDispatcher([
        DeliveryProviderName::DoorDash->value => fakeProvider(
            DeliveryProviderName::DoorDash,
            throwOnQuote: new RuntimeException('no driver'),
        ),
        DeliveryProviderName::Uber->value => fakeProvider(DeliveryProviderName::Uber),
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeFalse()
        ->and($result->attemptedProviders)->toBe(['doordash']);
});

it('blocks dispatch when a courier delivery contains restricted items', function () {
    $r = adminOrderRestaurant('restrictco');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => ['uber'],
    ]);
    $order = makeDeliveryOrder($r);
    OrderItem::create([
        'order_id' => $order->id,
        'name' => 'Bud Light Beer',
        'unit_price_cents' => 600,
        'quantity' => 1,
        'subtotal_cents' => 600,
        'modifiers' => null,
    ]);

    $dispatcher = new DeliveryDispatcher([
        DeliveryProviderName::Uber->value => fakeProvider(DeliveryProviderName::Uber),
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeFalse();
    expect($result->failureReason)->toStartWith('restricted_items');
    expect($result->failureReason)->toContain('Bud Light Beer');
    expect($order->fresh()->delivery_assignment_id)->toBeNull();
});

it('lets self-delivery carry items the courier guard would block', function () {
    // The restaurant's own drivers under its own licences are not DoorDash's
    // problem; the guard only screens third-party chains.
    $r = adminOrderRestaurant('selfbeer');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery,
    ]);
    $order = makeDeliveryOrder($r);
    OrderItem::create([
        'order_id' => $order->id,
        'name' => 'Bud Light Beer',
        'unit_price_cents' => 600,
        'quantity' => 1,
        'subtotal_cents' => 600,
        'modifiers' => null,
    ]);

    $dispatcher = new DeliveryDispatcher([
        DeliveryProviderName::Self->value => fakeProvider(DeliveryProviderName::Self),
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeTrue();
});

it('returns failure when delivery is not configured', function () {
    $r = adminOrderRestaurant('off');
    $order = makeDeliveryOrder($r);

    $dispatcher = new DeliveryDispatcher([]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeFalse()
        ->and($result->failureReason)->toBe('delivery_not_configured');
});

it('skips providers that do not support the restaurant', function () {
    $r = adminOrderRestaurant('skip');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => ['doordash', 'uber'],
    ]);
    $order = makeDeliveryOrder($r);

    $dispatcher = new DeliveryDispatcher([
        DeliveryProviderName::DoorDash->value => fakeProvider(DeliveryProviderName::DoorDash, supports: false),
        DeliveryProviderName::Uber->value => fakeProvider(DeliveryProviderName::Uber),
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeTrue()
        ->and($result->provider)->toBe(DeliveryProviderName::Uber);
});
