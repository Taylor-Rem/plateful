<?php

namespace App\Services\Delivery\UberDirect;

use App\Contracts\DeliveryProvider;
use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryIntegration;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryQuote;
use App\Services\Delivery\DeliveryQuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * Dispatches a paid Plateful order to the restaurant's own Uber Direct account
 * via the customer-scoped Direct API (`/v1/customers/{customer_id}/…`).
 *
 * Not to be confused with Uber's *store*-scoped Eats API
 * (`/v1/eats/deliveries/…`), which is a different product keyed on `store_id`
 * and Google Place ids. The scope that gates this API is confusingly named
 * `eats.deliveries` regardless.
 */
class UberDirectProvider implements DeliveryProvider
{
    public function __construct(
        private UberDirectClient $client,
        private UberDirectTokenService $tokens,
    ) {}

    public function name(): DeliveryProviderName
    {
        return DeliveryProviderName::Uber;
    }

    /**
     * Unlike SelfDeliveryProvider, this asks whether the restaurant actually has
     * usable credentials — `delivery_enabled` alone can't answer that once
     * credentials are per-tenant.
     */
    public function supports(Restaurant $restaurant): bool
    {
        return $this->findIntegration($restaurant) !== null;
    }

    public function quote(DeliveryQuoteRequest $request): DeliveryQuote
    {
        $integration = $this->integrationOrFail($request->restaurant);

        $pickup = UberDirectAddress::fromRestaurant($request->restaurant);
        $dropoff = UberDirectAddress::fromSnapshot($request->dropoffAddress);

        $response = $this->client
            ->authed($this->tokens->freshAccessToken($integration))
            ->post($this->client->customerPath((string) $integration->customer_id, '/delivery_quotes'), [
                'pickup_address' => $pickup,
                'dropoff_address' => $dropoff,
            ]);

        if ($response->failed()) {
            throw $this->failure('quote', $response);
        }

        return new DeliveryQuote(
            provider: $this->name(),
            // Uber returns the fee in cents already.
            feeCents: (int) $response->json('fee'),
            etaMinutes: $this->intOrNull($response->json('duration')),
            expiresAt: $this->timeOrNull($response->json('expires')),
            externalQuoteId: $this->stringOrNull($response->json('id')),
            dropoffEtaAt: $this->timeOrNull($response->json('dropoff_eta')),
            dropoffDeadlineAt: $this->timeOrNull($response->json('dropoff_deadline')),
            pickupDurationMinutes: $this->intOrNull($response->json('pickup_duration')),
            // Carry the exact strings forward so create() replays them verbatim
            // instead of re-encoding and risking `delivery location changed`.
            dropoffAddressPayload: $dropoff,
            pickupAddressPayload: $pickup,
        );
    }

    /**
     * NOTE — the courier tip is deliberately NOT sent yet.
     *
     * §0 commits to passing the customer's tip through to the courier, which
     * Uber's merchant terms require. But the field name is unverified: the
     * customer-scoped create example documents no tip field, and the two
     * candidates (`tip` here vs `courier_tip`) belong to two different Uber
     * APIs. A silently-ignored field would mean tips quietly never reaching
     * couriers — strictly worse than not sending one, because it looks like it
     * works. It lands in §6, where checkout actually carries the tip through,
     * gated on confirming the field against the live sandbox first.
     */
    public function create(Order $order, DeliveryQuote $quote): DeliveryAssignment
    {
        $restaurant = $order->restaurant;
        $integration = $this->integrationOrFail($restaurant);

        $response = $this->client
            ->authed($this->tokens->freshAccessToken($integration))
            ->post($this->client->customerPath((string) $integration->customer_id, '/deliveries'), [
                'quote_id' => $quote->externalQuoteId,
                // Replayed byte-identical from the quote — see UberDirectAddress.
                'pickup_address' => $quote->pickupAddressPayload ?? UberDirectAddress::fromRestaurant($restaurant),
                'pickup_name' => $restaurant->name,
                'pickup_phone_number' => (string) $restaurant->phone,
                'dropoff_address' => $quote->dropoffAddressPayload ?? UberDirectAddress::fromSnapshot((array) $order->delivery_address),
                'dropoff_name' => (string) $order->customer_name,
                'dropoff_phone_number' => (string) $order->customer_phone,
                'dropoff_notes' => $this->dropoffNotes($order),
                'manifest_items' => $this->manifestItems($order),
                'manifest_reference' => $order->number,
                // Lets the status webhook find the order without a lookup table.
                'external_id' => $order->number,
            ]);

        if ($response->failed()) {
            throw $this->failure('create', $response);
        }

        $externalId = $this->stringOrNull($response->json('id'));

        if ($externalId === null) {
            throw DeliveryProviderException::createFailed('uber', 'Uber returned no delivery id.');
        }

        return DeliveryAssignment::create([
            'order_id' => $order->id,
            'provider' => $this->name(),
            'external_id' => $externalId,
            'status' => UberDirectStatusMap::toDeliveryStatus($this->stringOrNull($response->json('status'))),
            'quote_fee_cents' => $quote->feeCents,
            // What Uber will actually bill, which may differ from the quote the
            // customer was charged. Recording both is what makes the drift
            // measurable rather than a guess.
            'actual_fee_cents' => $this->intOrNull($response->json('fee')),
            'tracking_url' => $this->stringOrNull($response->json('tracking_url')),
            'pickup_eta_at' => $this->timeOrNull($response->json('pickup_eta')),
            'dropoff_eta_at' => $this->timeOrNull($response->json('dropoff_eta')),
            ...$this->courierFields($response->json('courier')),
        ]);
    }

    public function status(DeliveryAssignment $assignment): DeliveryAssignment
    {
        $order = $assignment->order;
        $integration = $this->integrationOrFail($order->restaurant);

        $response = $this->client
            ->authed($this->tokens->freshAccessToken($integration))
            ->get($this->client->customerPath(
                (string) $integration->customer_id,
                '/deliveries/'.$assignment->external_id,
            ));

        if ($response->failed()) {
            throw $this->failure('status', $response);
        }

        $assignment->forceFill([
            'status' => UberDirectStatusMap::toDeliveryStatus($this->stringOrNull($response->json('status'))),
            'actual_fee_cents' => $this->intOrNull($response->json('fee')) ?? $assignment->actual_fee_cents,
            'tracking_url' => $this->stringOrNull($response->json('tracking_url')) ?? $assignment->tracking_url,
            'pickup_eta_at' => $this->timeOrNull($response->json('pickup_eta')) ?? $assignment->pickup_eta_at,
            'dropoff_eta_at' => $this->timeOrNull($response->json('dropoff_eta')) ?? $assignment->dropoff_eta_at,
            ...$this->courierFields($response->json('courier')),
        ])->save();

        return $assignment;
    }

    public function cancel(DeliveryAssignment $assignment): void
    {
        $order = $assignment->order;
        $integration = $this->integrationOrFail($order->restaurant);

        $response = $this->client
            ->authed($this->tokens->freshAccessToken($integration))
            ->post($this->client->customerPath(
                (string) $integration->customer_id,
                '/deliveries/'.$assignment->external_id.'/cancel',
            ));

        if ($response->failed()) {
            throw $this->failure('cancel', $response);
        }

        $assignment->forceFill(['status' => DeliveryStatus::Cancelled])->save();
    }

    /**
     * Uber's courier object is null until one is assigned. Its arrival is the
     * signal the whole auth/capture design waits on (§8).
     *
     * @param  mixed  $courier
     * @return array<string, string|null>
     */
    private function courierFields($courier): array
    {
        if (! is_array($courier)) {
            return [];
        }

        return [
            'driver_name' => $this->stringOrNull($courier['name'] ?? null),
            'driver_phone' => $this->stringOrNull($courier['phone_number'] ?? null),
        ];
    }

    /**
     * Uber requires a manifest describing what the courier is carrying.
     *
     * @return array<int, array<string, mixed>>
     */
    private function manifestItems(Order $order): array
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

        return $items->map(fn (OrderItem $item): array => [
            'name' => $item->name,
            'quantity' => max(1, (int) $item->quantity),
            'size' => 'small',
        ])->values()->all();
    }

    private function dropoffNotes(Order $order): string
    {
        $address = (array) $order->delivery_address;

        return (string) ($address['instructions'] ?? '');
    }

    private function failure(string $operation, Response $response): DeliveryProviderException
    {
        return DeliveryProviderException::createFailed(
            'uber',
            "Uber {$operation} failed (HTTP {$response->status()}): ".$response->body(),
        );
    }

    private function findIntegration(Restaurant $restaurant): ?DeliveryIntegration
    {
        // Unscoped so it resolves inside queue workers, where no tenant is
        // bound — same reason PosDispatcher::integrationFor does.
        return DeliveryIntegration::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('provider', DeliveryProviderName::Uber->value)
            ->where('status', DeliveryIntegrationStatus::Connected->value)
            ->first();
    }

    private function integrationOrFail(Restaurant $restaurant): DeliveryIntegration
    {
        return $this->findIntegration($restaurant)
            ?? throw DeliveryProviderException::notConfigured(DeliveryProviderName::Uber->value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function timeOrNull(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
