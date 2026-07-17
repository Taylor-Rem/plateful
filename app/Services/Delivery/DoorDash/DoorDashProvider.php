<?php

namespace App\Services\Delivery\DoorDash;

use App\Contracts\DeliveryProvider;
use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryIntegration;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryCancellation;
use App\Services\Delivery\DeliveryQuote;
use App\Services\Delivery\DeliveryQuoteRequest;
use App\Services\Delivery\UberDirect\UberDirectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dispatches a paid Plateful order to DoorDash Drive.
 *
 * Structurally different from {@see UberDirectProvider}
 * in three ways that shape this class:
 *
 *   1. **Umbrella / central billing.** One set of platform credentials (a signed
 *      DD-JWT-V1, minted per request by {@see DoorDashClient}) authenticates
 *      every restaurant. A restaurant is identified not by its own account but by
 *      a Business + Store that Plateful provisions (Session 2) and stores as
 *      `external_business_id` / `external_store_id` on the integration row.
 *
 *   2. **One id spans the whole lifecycle.** We generate an `external_delivery_id`
 *      at quote time; the same id is what we accept, poll, cancel and receive
 *      webhooks for. It is carried on the DeliveryQuote as `externalQuoteId`.
 *
 *   3. **Quote → accept, not quote → create.** DoorDash's `create` step is
 *      accepting the existing quote by id — the accept locks the fee the quote
 *      returned. The accept body does NOT resend addresses (they are bound to the
 *      quote), so there is no byte-identical replay contract the way Uber has.
 *
 * The money model (customer gross-up, central-billing recovery) lands in Session
 * 4; this adapter reuses the existing fee logic unchanged.
 */
class DoorDashProvider implements DeliveryProvider
{
    public function __construct(private DoorDashClient $client) {}

    public function name(): DeliveryProviderName
    {
        return DeliveryProviderName::DoorDash;
    }

    /**
     * Supported once Plateful has provisioned a Store for this restaurant — the
     * `external_store_id` is what every quote is keyed on. Credential presence is
     * never in question here: the credentials are platform-level, in config.
     */
    public function supports(Restaurant $restaurant): bool
    {
        return $this->findIntegration($restaurant) !== null;
    }

    public function quote(DeliveryQuoteRequest $request): DeliveryQuote
    {
        $integration = $this->integrationOrFail($request->restaurant);
        $externalDeliveryId = $this->generateDeliveryId();

        $response = $this->client
            ->authed()
            ->post($this->client->drivePath('/quotes'), $this->quotePayload(
                $externalDeliveryId,
                $request->restaurant,
                $integration,
                (array) $request->dropoffAddress,
                $request->customerName,
                $request->customerPhone,
            ));

        if ($response->failed()) {
            throw $this->failure('quote', $response);
        }

        return new DeliveryQuote(
            provider: $this->name(),
            // DoorDash returns the courier fee in cents, excluding the tip
            // (which is a separate field on accept, unlike Uber's fee).
            feeCents: (int) $response->json('fee'),
            etaMinutes: $this->intOrNull($response->json('duration')),
            // DoorDash returns no expiry; stamp a synthetic one so
            // DeliveryDispatcher::quoteForDispatch re-quotes before the ~5 min
            // accept window lapses rather than accepting a dead quote.
            expiresAt: $this->syntheticExpiry(),
            // Our own id is the key through accept/status/cancel/webhook. Read it
            // back from the echo, falling back to what we sent.
            externalQuoteId: $this->stringOrNull($response->json('external_delivery_id')) ?? $externalDeliveryId,
            dropoffEtaAt: $this->timeOrNull($response->json('dropoff_time_estimated')),
            pickupDurationMinutes: null,
        );
    }

    /**
     * Accept the quote — DoorDash's commit step — passing the driver tip.
     *
     * If the accept fails because the quote expired or was already consumed
     * (Risk R1: a customer can linger on Stripe's page past the ~5 min window),
     * re-quote fresh under a NEW `external_delivery_id` and accept that. The
     * assignment then keys off the id we actually accepted.
     */
    public function create(Order $order, DeliveryQuote $quote): DeliveryAssignment
    {
        $restaurant = $order->restaurant;
        $this->integrationOrFail($restaurant);
        $tipCents = max(0, (int) $order->tip_cents);
        $externalDeliveryId = (string) $quote->externalQuoteId;

        $response = $this->accept($externalDeliveryId, $tipCents);

        if ($response->failed()) {
            Log::warning('DoorDash accept failed; re-quoting before giving up', [
                'order_id' => $order->id,
                'external_delivery_id' => $externalDeliveryId,
                'status' => $response->status(),
            ]);

            $fresh = $this->quote($this->quoteRequestFromOrder($order));
            $externalDeliveryId = (string) $fresh->externalQuoteId;
            $response = $this->accept($externalDeliveryId, $tipCents);

            if ($response->failed()) {
                throw $this->failure('create', $response);
            }
        }

        $externalId = $this->stringOrNull($response->json('external_delivery_id')) ?? $externalDeliveryId;

        return DeliveryAssignment::create([
            'order_id' => $order->id,
            'provider' => $this->name(),
            'external_id' => $externalId,
            'status' => DoorDashStatusMap::toDeliveryStatus($this->stringOrNull($response->json('delivery_status'))),
            'quote_fee_cents' => $quote->feeCents,
            // DoorDash's fee excludes the tip, so it is already apples-to-apples
            // with quote_fee_cents — no tip stripping the way Uber needs.
            'actual_fee_cents' => $this->intOrNull($response->json('fee')),
            'tracking_url' => $this->stringOrNull($response->json('tracking_url')),
            'pickup_eta_at' => $this->timeOrNull($response->json('pickup_time_estimated')),
            'dropoff_eta_at' => $this->timeOrNull($response->json('dropoff_time_estimated')),
            ...$this->dasherFields($response->json()),
        ]);
    }

    public function status(DeliveryAssignment $assignment): DeliveryAssignment
    {
        $this->integrationOrFail($assignment->order->restaurant);

        $response = $this->client
            ->authed()
            ->get($this->client->drivePath('/deliveries/'.$assignment->external_id));

        if ($response->failed()) {
            throw $this->failure('status', $response);
        }

        $assignment->forceFill([
            'status' => DoorDashStatusMap::toDeliveryStatus($this->stringOrNull($response->json('delivery_status'))),
            'actual_fee_cents' => $this->intOrNull($response->json('fee')) ?? $assignment->actual_fee_cents,
            'tracking_url' => $this->stringOrNull($response->json('tracking_url')) ?? $assignment->tracking_url,
            'pickup_eta_at' => $this->timeOrNull($response->json('pickup_time_estimated')) ?? $assignment->pickup_eta_at,
            'dropoff_eta_at' => $this->timeOrNull($response->json('dropoff_time_estimated')) ?? $assignment->dropoff_eta_at,
            ...$this->dasherFields($response->json()),
        ])->save();

        return $assignment;
    }

    public function cancel(DeliveryAssignment $assignment): DeliveryCancellation
    {
        $this->integrationOrFail($assignment->order->restaurant);

        $response = $this->client
            ->authed()
            ->put($this->client->drivePath('/deliveries/'.$assignment->external_id.'/cancel'));

        if ($response->failed()) {
            throw $this->failure('cancel', $response);
        }

        $assignment->forceFill(['status' => DeliveryStatus::Cancelled])->save();

        return $this->parseCancellation((array) $response->json(), (int) $assignment->actual_fee_cents);
    }

    /**
     * Read DoorDash's cancel response to learn whether the courier fee is
     * recoverable. Cancel before the Dasher picks up and DoorDash charges
     * nothing (fully refundable); cancel after pickup and DoorDash keeps the
     * fee.
     *
     * The exact field DoorDash returns for a cancellation charge is unverified
     * against a live cancel (plan Session 3/6 open item) — the Drive docs are
     * thin here — so this reads the plausible candidates and, crucially,
     * DEFAULTS TO RETAINED when the response is silent. Refunding the delivery
     * line when DoorDash actually kept the fee would make Plateful eat it; the
     * conservative default keeps Plateful whole. Confirm the field at portal
     * setup and tighten this one method.
     *
     * @param  array<string, mixed>  $body
     */
    private function parseCancellation(array $body, int $courierFeeCents): DeliveryCancellation
    {
        // Candidate shapes: a boolean/flag saying the fee was waived, or an
        // explicit cancellation-fee amount in cents.
        $waived = $body['cancellation_fee_waived']
            ?? $body['fee_waived']
            ?? ($body['refund'] ?? null);

        if ($waived === true) {
            return DeliveryCancellation::fullyRefunded();
        }

        foreach (['cancellation_fee', 'cancellation_fee_cents', 'fee'] as $key) {
            if (array_key_exists($key, $body) && is_numeric($body[$key])) {
                $charged = (int) $body[$key];

                return $charged <= 0
                    ? DeliveryCancellation::fullyRefunded()
                    : DeliveryCancellation::courierFeeRetained($charged);
            }
        }

        // Response said nothing about a fee: assume DoorDash kept the courier
        // fee. Never refund the delivery line on a guess.
        return DeliveryCancellation::courierFeeRetained($courierFeeCents);
    }

    private function accept(string $externalDeliveryId, int $tipCents): Response
    {
        return $this->client
            ->authed()
            ->post($this->client->drivePath('/quotes/'.$externalDeliveryId.'/accept'), [
                // A third-party delivery tip is unambiguously the Dasher's; on
                // accept DoorDash forwards it to them (dollars, in cents here).
                'tip' => $tipCents,
            ]);
    }

    /**
     * The body DoorDash's `POST /drive/v2/quotes` expects.
     *
     * Because we run multiple locations under one platform account,
     * `pickup_external_business_id` + `pickup_external_store_id` are REQUIRED and
     * identify which restaurant this quote is for. We also send the pickup
     * address components so DoorDash can geocode without a round-trip to the
     * stored store record.
     *
     * @param  array<string, mixed>  $dropoffAddress
     * @return array<string, mixed>
     */
    private function quotePayload(
        string $externalDeliveryId,
        Restaurant $restaurant,
        DeliveryIntegration $integration,
        array $dropoffAddress,
        ?string $customerName,
        ?string $customerPhone,
    ): array {
        [$givenName, $familyName] = $this->splitName($customerName);

        return array_filter([
            'external_delivery_id' => $externalDeliveryId,
            'pickup_external_business_id' => (string) $integration->external_business_id,
            'pickup_external_store_id' => (string) $integration->external_store_id,
            'pickup_address' => DoorDashAddress::fromRestaurant($restaurant),
            'pickup_business_name' => $restaurant->name,
            'pickup_phone_number' => $this->normalizePhone((string) $restaurant->phone),
            'dropoff_address' => DoorDashAddress::fromSnapshot($dropoffAddress),
            'dropoff_contact_given_name' => $givenName,
            'dropoff_contact_family_name' => $familyName,
            'dropoff_phone_number' => $this->normalizePhone((string) $customerPhone),
            'dropoff_instructions' => $this->stringOrNull($dropoffAddress['instructions'] ?? null),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * DoorDash's Dasher fields are null until one is assigned. Their arrival is
     * the signal the whole auth/capture design waits on (§8).
     *
     * @param  mixed  $payload
     * @return array<string, string|null>
     */
    private function dasherFields($payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $name = $this->stringOrNull($payload['dasher_name'] ?? null);
        $phone = $this->stringOrNull(
            $payload['dasher_dropoff_phone_number']
            ?? $payload['dasher_pickup_phone_number']
            ?? null,
        );

        return array_filter([
            'driver_name' => $name,
            'driver_phone' => $phone,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Reconstruct a quote request from a paid order, for the R1 re-quote path.
     */
    private function quoteRequestFromOrder(Order $order): DeliveryQuoteRequest
    {
        return new DeliveryQuoteRequest(
            restaurant: $order->restaurant,
            dropoffAddress: (array) ($order->delivery_address ?? []),
            subtotalCents: (int) $order->subtotal_cents,
            tipCents: (int) $order->tip_cents,
            customerName: $order->customer_name,
            customerPhone: $order->customer_phone,
            order: $order,
        );
    }

    private function generateDeliveryId(): string
    {
        return 'pf-'.Str::uuid()->toString();
    }

    private function syntheticExpiry(): CarbonImmutable
    {
        $minutes = max(1, (int) config('platform.delivery.doordash.quote_accept_window_minutes', 5));

        return CarbonImmutable::now()->addMinutes($minutes);
    }

    /**
     * Best-effort E.164 for a bare 10-digit US number; passed through otherwise.
     * DoorDash wants a dialable number, and the stored numbers are unformatted.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return str_starts_with($phone, '+') ? $phone : '+'.$digits;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['Customer', ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $given = array_shift($parts);

        return [$given, implode(' ', $parts)];
    }

    private function findIntegration(Restaurant $restaurant): ?DeliveryIntegration
    {
        // Unscoped so it resolves inside queue workers, where no tenant is
        // bound — same reason UberDirectProvider::findIntegration is.
        return DeliveryIntegration::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('provider', DeliveryProviderName::DoorDash->value)
            ->where('status', DeliveryIntegrationStatus::Connected->value)
            ->whereNotNull('external_store_id')
            ->first();
    }

    private function integrationOrFail(Restaurant $restaurant): DeliveryIntegration
    {
        return $this->findIntegration($restaurant)
            ?? throw DeliveryProviderException::notConfigured(DeliveryProviderName::DoorDash->value);
    }

    private function failure(string $operation, Response $response): DeliveryProviderException
    {
        return DeliveryProviderException::createFailed(
            'doordash',
            "DoorDash {$operation} failed (HTTP {$response->status()}): ".$response->body(),
        );
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
