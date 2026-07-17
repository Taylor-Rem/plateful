<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\PaymentState;
use App\Models\DeliveryAssignment;
use App\Models\OrderEvent;
use App\Services\Delivery\DeliverySettlement;
use App\Services\Delivery\DoorDash\DoorDashStatusMap;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives DoorDash Drive delivery-status webhooks on one platform URL.
 *
 * Unlike {@see UberDirectWebhookController}, DoorDash is centrally billed: there
 * is ONE platform-level webhook secret, not a per-restaurant key, so the payload
 * does not need to name which restaurant it belongs to. We verify the signature
 * against that single secret and then resolve the delivery straight from the
 * `external_delivery_id` we generated — the id that spans the whole lifecycle.
 *
 * The courier's existence is the signal §8 waits on: once a Dasher is confirmed
 * a held payment is captured, and if DoorDash cancels the hold is released —
 * both through the same {@see DeliverySettlement} path Uber uses.
 */
class DoorDashWebhookController extends Controller
{
    /**
     * DoorDash signs the raw body with the shared webhook secret and sends the
     * result in this header. The exact scheme (HMAC-SHA256; base64 vs hex) is
     * confirmed against the DoorDash portal — {@see signatureIsValid()} accepts
     * both encodings so a portal setting can't silently drop every event, and it
     * is the single place to adjust if the scheme differs.
     */
    private const SIGNATURE_HEADER = 'x-doordash-signature';

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.doordash.webhook_secret');

        // Fail closed: with no secret we can vouch for nothing. 400 rather than
        // 200 so DoorDash retries once the secret is configured.
        if ($secret === '' || ! $this->signatureIsValid($request, $secret)) {
            return response('Invalid signature.', 400);
        }

        $payload = (array) $request->json()->all();
        $assignment = $this->resolveAssignment($payload);

        if ($assignment === null) {
            // A delivery we have no record of. Acknowledge — retrying will not
            // conjure the assignment, and DoorDash would keep hammering us.
            Log::info('DoorDash webhook for unknown delivery', [
                'external_delivery_id' => $payload['external_delivery_id'] ?? null,
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
        $eventAt = $this->timeOrNull(
            $payload['created_at'] ?? $payload['event_created_time'] ?? null,
        );

        // DoorDash retries, so a stale event can arrive after a newer one. Its
        // own clock decides ordering; anything not newer than what we already
        // applied is dropped.
        if ($eventAt !== null
            && $assignment->last_event_at !== null
            && ! $eventAt->isAfter($assignment->last_event_at)) {
            return;
        }

        $previous = $assignment->status;
        $status = DoorDashStatusMap::toDeliveryStatus($this->stringOrNull($payload['delivery_status'] ?? null));

        $assignment->forceFill(array_filter([
            'status' => $status,
            'last_event_at' => $eventAt ?? now(),
            'tracking_url' => $this->stringOrNull($payload['tracking_url'] ?? null) ?? $assignment->tracking_url,
            // DoorDash's fee excludes the tip, so it needs no correction to stay
            // comparable with quote_fee_cents (unlike Uber's tip-inclusive fee).
            'actual_fee_cents' => $this->intOrNull($payload['fee'] ?? null) ?? $assignment->actual_fee_cents,
            'driver_name' => $this->stringOrNull($payload['dasher_name'] ?? null) ?? $assignment->driver_name,
            'driver_phone' => $this->stringOrNull(
                $payload['dasher_dropoff_phone_number'] ?? $payload['dasher_phone_number'] ?? null,
            ) ?? $assignment->driver_phone,
        ], fn ($value): bool => $value !== null))->save();

        if ($previous !== $status) {
            $order = $assignment->order;

            if ($order !== null) {
                OrderEvent::note($order, "Delivery {$status->value} (DoorDash)");
            }
        }

        $this->settlePayment($assignment->fresh(), $status, $payload);
    }

    /**
     * The courier's existence is the signal that a held payment may become a
     * real one. Both branches no-op unless the order is still Authorized, so a
     * retried or duplicated event settles nothing twice.
     *
     * @param  array<string, mixed>  $payload
     */
    private function settlePayment(?DeliveryAssignment $assignment, DeliveryStatus $status, array $payload): void
    {
        $order = $assignment?->order;

        if ($order === null || $order->payment_state !== PaymentState::Authorized) {
            return;
        }

        $settlement = app(DeliverySettlement::class);

        // A Dasher is assigned and coming. Take the money, print the ticket.
        if ($status->hasCourier()) {
            $settlement->onCourierConfirmed($order);

            return;
        }

        // DoorDash gave up. Release the hold rather than leave it sitting.
        if (in_array($status, [DeliveryStatus::Cancelled, DeliveryStatus::Failed], strict: true)) {
            $reason = $this->stringOrNull($payload['cancellation_reason'] ?? null)
                ?? 'the courier network cancelled the delivery';

            $settlement->onCourierUnavailable($order, $reason);
        }
    }

    /**
     * Resolve the delivery straight from `external_delivery_id` — the id we
     * generated and that DoorDash echoes on every event. Rides the same
     * [provider, external_id] index the assignments table has always had.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveAssignment(array $payload): ?DeliveryAssignment
    {
        $deliveryId = $this->stringOrNull($payload['external_delivery_id'] ?? null);

        if ($deliveryId === null) {
            return null;
        }

        return DeliveryAssignment::query()
            ->with('order')
            ->where('provider', DeliveryProviderName::DoorDash->value)
            ->where('external_id', $deliveryId)
            ->first();
    }

    private function signatureIsValid(Request $request, string $secret): bool
    {
        $provided = (string) $request->header(self::SIGNATURE_HEADER, '');

        if ($provided === '') {
            return false;
        }

        $raw = hash_hmac('sha256', $request->getContent(), $secret, true);

        // Accept both common encodings; hash_equals keeps each check
        // constant-time.
        foreach ([base64_encode($raw), bin2hex($raw)] as $expected) {
            if (hash_equals($expected, $provided)) {
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
