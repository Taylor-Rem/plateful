<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryProviderName;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryIntegration;
use App\Models\OrderEvent;
use App\Services\Delivery\UberDirect\UberDirectStatusMap;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Uber Direct delivery-status webhooks for every tenant on one URL.
 *
 * **The signing key is per-restaurant.** Each restaurant owns its Uber account
 * and creates the webhook inside its own dashboard, so Uber mints a distinct
 * signing key per restaurant — there is no platform-wide secret to check
 * against. That inverts the usual order of operations: we must work out *which*
 * restaurant an unverified payload claims to be for, look up their key, and
 * only then verify.
 *
 * That is safe because the claimed identity selects the key but grants nothing:
 * a forged `customer_id` just means we check the signature against a different
 * restaurant's key, which then fails. The signature remains the only thing that
 * authorizes any write.
 */
class UberDirectWebhookController extends Controller
{
    /**
     * Uber sends one of two header names depending on the event type — the
     * docs specify `x-postmates-signature` for delivery/courier events and
     * `x-uber-signature` for others. Accept both rather than bet on one.
     *
     * @var list<string>
     */
    private const SIGNATURE_HEADERS = ['x-uber-signature', 'x-postmates-signature'];

    public function __invoke(Request $request): Response
    {
        $payload = (array) $request->json()->all();

        $integration = $this->resolveIntegration($payload);

        if ($integration === null || $integration->webhook_signing_key === null) {
            // Nothing to verify against. 400 rather than 200: this is not an
            // event we can vouch for, and a retry costs us nothing.
            return response('Unknown or unconfigured customer.', 400);
        }

        if (! $this->signatureIsValid($request, (string) $integration->webhook_signing_key)) {
            return response('Invalid signature.', 400);
        }

        $kind = $this->stringOrNull($payload['kind'] ?? null);

        // Acknowledge kinds we don't handle so Uber stops retrying them.
        if (! in_array($kind, ['event.delivery_status', 'event.courier_update'], strict: true)) {
            return response('Ignored.', 200);
        }

        $assignment = $this->resolveAssignment($payload);

        if ($assignment === null) {
            // A delivery we have no record of. Acknowledge — retrying will not
            // conjure the assignment, and Uber would keep hammering us.
            Log::info('Uber webhook for unknown delivery', [
                'delivery_id' => $payload['delivery_id'] ?? null,
            ]);

            return response('Unknown delivery.', 200);
        }

        $this->applyEvent($assignment, $payload);

        return response('OK', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyEvent(DeliveryAssignment $assignment, array $payload): void
    {
        $eventAt = $this->timeOrNull($payload['created'] ?? null);

        // Uber retries at 10s/40s/100s/220s, so a stale `pending` can arrive
        // after `delivered`. Uber's own clock decides ordering; anything not
        // newer than what we already applied is dropped.
        if ($eventAt !== null
            && $assignment->last_event_at !== null
            && ! $eventAt->isAfter($assignment->last_event_at)) {
            return;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $previous = $assignment->status;
        $status = UberDirectStatusMap::toDeliveryStatus($this->stringOrNull($payload['status'] ?? null));
        $courier = is_array($data['courier'] ?? null) ? $data['courier'] : null;

        $assignment->forceFill(array_filter([
            'status' => $status,
            'last_event_at' => $eventAt ?? now(),
            'tracking_url' => $this->stringOrNull($data['tracking_url'] ?? null) ?? $assignment->tracking_url,
            'actual_fee_cents' => $this->intOrNull($data['fee'] ?? null) ?? $assignment->actual_fee_cents,
            'pickup_eta_at' => $this->timeOrNull($data['pickup_eta'] ?? null) ?? $assignment->pickup_eta_at,
            'dropoff_eta_at' => $this->timeOrNull($data['dropoff_eta'] ?? null) ?? $assignment->dropoff_eta_at,
            'driver_name' => $this->stringOrNull($courier['name'] ?? null) ?? $assignment->driver_name,
            'driver_phone' => $this->stringOrNull($courier['phone_number'] ?? null) ?? $assignment->driver_phone,
        ], fn ($value): bool => $value !== null))->save();

        if ($previous !== $status) {
            $order = $assignment->order;

            if ($order !== null) {
                OrderEvent::note($order, "Delivery {$status->value} (Uber)");
            }
        }
    }

    /**
     * Which restaurant's Uber account is this event claiming to come from?
     * `customer_id` is on every Direct payload and maps straight to the
     * integration that holds the key we need.
     *
     * Strictly `customer_id`, with no fall back to looking the restaurant up
     * from `delivery_id`: such a fallback would quietly make the claimed
     * customer irrelevant whenever a delivery id happened to match, so a
     * payload could name one account and be judged against another's key.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveIntegration(array $payload): ?DeliveryIntegration
    {
        $customerId = $this->stringOrNull($payload['customer_id'] ?? null);

        if ($customerId === null) {
            return null;
        }

        return DeliveryIntegration::withoutTenantScope()
            ->where('provider', DeliveryProviderName::Uber->value)
            ->where('customer_id', $customerId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveAssignment(array $payload): ?DeliveryAssignment
    {
        $deliveryId = $this->stringOrNull($payload['delivery_id'] ?? null);

        if ($deliveryId === null) {
            return null;
        }

        // The [provider, external_id] index this rides on has been on
        // delivery_assignments since the table was created.
        return DeliveryAssignment::query()
            ->with('order')
            ->where('provider', DeliveryProviderName::Uber->value)
            ->where('external_id', $deliveryId)
            ->first();
    }

    private function signatureIsValid(Request $request, string $signingKey): bool
    {
        $expected = hash_hmac('sha256', $request->getContent(), $signingKey);

        foreach (self::SIGNATURE_HEADERS as $header) {
            $provided = (string) $request->header($header, '');

            // hash_equals to keep the comparison constant-time.
            if ($provided !== '' && hash_equals($expected, $provided)) {
                return true;
            }
        }

        return false;
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
