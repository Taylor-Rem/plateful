<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\PaymentState;
use App\Models\DeliveryAssignment;
use App\Models\OrderEvent;
use App\Services\Delivery\DeliverySettlement;
use App\Services\Delivery\UberDirect\UberDirectStatusMap;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Uber Direct delivery-status webhooks for every tenant on one URL.
 *
 * Umbrella model: the webhook is configured once on Plateful's root Direct
 * account and signed with ONE platform-level key
 * (`services.uber_direct.webhook_secret`), so — like the DoorDash webhook —
 * the signature is verified before anything else and events resolve straight
 * to the assignment by `delivery_id`. No per-restaurant key lookup exists.
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
        $secret = (string) config('services.uber_direct.webhook_secret');

        // Fail closed: with no secret we can vouch for nothing. 400 rather than
        // 200 so Uber retries once the secret is configured.
        if ($secret === '' || ! $this->signatureIsValid($request, $secret)) {
            return response('Invalid signature.', 400);
        }

        $payload = (array) $request->json()->all();

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

        // Uber's delivery `fee` includes the tip, but `quote_fee_cents` cannot
        // — no tip exists at quote time. Strip it back out so the two stay
        // comparable; see UberDirectProvider::deliveryFeeExcludingTip().
        $tipCents = max(0, (int) ($assignment->order?->tip_cents ?? 0));
        $rawFee = $this->intOrNull($data['fee'] ?? null);

        $assignment->forceFill(array_filter([
            'status' => $status,
            'last_event_at' => $eventAt ?? now(),
            'tracking_url' => $this->stringOrNull($data['tracking_url'] ?? null) ?? $assignment->tracking_url,
            'actual_fee_cents' => ($rawFee === null ? null : max(0, $rawFee - $tipCents))
                ?? $assignment->actual_fee_cents,
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

        $this->settlePayment($assignment->fresh(), $status, $data);
    }

    /**
     * This webhook is where §8's whole design lands: the courier's existence is
     * the signal that a held payment may become a real one.
     *
     * Both branches no-op unless the order is still Authorized, so a retried or
     * duplicated event settles nothing twice.
     *
     * @param  array<string, mixed>  $data
     */
    private function settlePayment(?DeliveryAssignment $assignment, DeliveryStatus $status, array $data): void
    {
        $order = $assignment?->order;

        if ($order === null || $order->payment_state !== PaymentState::Authorized) {
            return;
        }

        $settlement = app(DeliverySettlement::class);

        // A courier exists and is coming. Take the money, print the ticket.
        if ($status->hasCourier()) {
            $settlement->onCourierConfirmed($order);

            return;
        }

        // Uber gave up. Release the hold rather than leave it sitting.
        if (in_array($status, [DeliveryStatus::Cancelled, DeliveryStatus::Failed], strict: true)) {
            $reason = $this->stringOrNull($data['undeliverable_reason'] ?? null)
                ?? 'the courier network cancelled the delivery';

            $settlement->onCourierUnavailable($order, $reason);
        }
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

    private function signatureIsValid(Request $request, string $secret): bool
    {
        $raw = hash_hmac('sha256', $request->getContent(), $secret, true);

        foreach (self::SIGNATURE_HEADERS as $header) {
            $provided = (string) $request->header($header, '');

            if ($provided === '') {
                continue;
            }

            // Uber documents hex; accept base64 too (as the DoorDash webhook
            // does) so a dashboard encoding difference can't silently drop
            // every event. hash_equals keeps each check constant-time.
            foreach ([bin2hex($raw), base64_encode($raw)] as $expected) {
                if (hash_equals($expected, $provided)) {
                    return true;
                }
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
