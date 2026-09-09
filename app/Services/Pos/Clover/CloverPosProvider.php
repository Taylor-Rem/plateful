<?php

namespace App\Services\Pos\Clover;

use App\Contracts\PosProvider;
use App\Enums\PosProviderName;
use App\Exceptions\PosProviderException;
use App\Exceptions\PosTokenExpiredException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Services\Pos\PosPushResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Injects a paid Plateful order into the restaurant's Clover account via the
 * atomic-order endpoint. Like Square, v1 is a text-fallback: each line is an
 * ad-hoc line item priced at what the customer actually paid, with selected
 * options folded into a note. Plateful stays the pricing authority; the Clover
 * order is for fulfillment, not re-pricing. The guided catalog matcher (§2b)
 * referencing real Clover inventory ids is a later phase.
 *
 * Clover has no per-line quantity field for ad-hoc items — the register groups
 * and counts identical line items — so a quantity of N is sent as N identical
 * lines. Clover's merchant id is the order-scoping key (its "location").
 *
 * The customer already paid through Stripe, so after the ticket is created we
 * record that payment against it using the merchant's built-in "External
 * payment" tender. Without it the ticket sits OPEN on the register and staff
 * may try to collect a second time. The payment step is best-effort: the ticket
 * is what the kitchen needs, so a failure there is logged, not retried (a retry
 * would create a duplicate ticket).
 */
class CloverPosProvider implements PosProvider
{
    /**
     * Clover's line-item note cap; selected options beyond this are truncated.
     */
    private const NOTE_LIMIT = 500;

    /**
     * Clover's system tender for payments taken outside Clover.
     */
    public const EXTERNAL_TENDER_LABEL_KEY = 'com.clover.tender.external_payment';

    /**
     * How long a merchant's external-tender id is cached (it never changes).
     */
    private const TENDER_CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private CloverClient $client,
        private CloverOAuthService $oauth,
    ) {}

    public function name(): PosProviderName
    {
        return PosProviderName::Clover;
    }

    public function supports(Restaurant $restaurant): bool
    {
        // The connected integration is the real per-restaurant gate (checked by
        // the dispatcher); here we only guard against a totally unconfigured app.
        return config('services.clover.app_id') !== null;
    }

    public function pushOrder(Order $order, PosIntegration $integration): PosPushResult
    {
        $merchantId = $integration->external_merchant_id;

        if ($merchantId === null) {
            throw PosProviderException::pushFailed('Clover integration is missing a merchant id; reconnect required.');
        }

        $accessToken = $this->freshAccessToken($integration);

        $response = $this->client->authed($accessToken)
            ->post("/v3/merchants/{$merchantId}/atomic_order/orders", [
                'orderCart' => $this->buildOrderCart($order),
            ]);

        if ($response->status() === 401) {
            throw PosTokenExpiredException::for(PosProviderName::Clover);
        }

        if ($response->failed()) {
            throw PosProviderException::pushFailed('Clover order create failed: '.$response->body());
        }

        $ticketId = $response->json('id');

        if (! is_string($ticketId) || $ticketId === '') {
            throw PosProviderException::pushFailed('Clover order create returned no order id.');
        }

        $this->recordExternalPayment($order, $merchantId, $ticketId, $accessToken);

        return PosPushResult::ok(PosProviderName::Clover, $ticketId);
    }

    /**
     * Mark the Clover ticket as paid with the amount the customer paid Plateful
     * for the food (subtotal + tax; tip is its own field). The delivery fee is
     * not the restaurant's money, so it is left off the register. Best-effort:
     * see the class docblock.
     */
    private function recordExternalPayment(Order $order, string $merchantId, string $ticketId, string $accessToken): void
    {
        $amount = (int) $order->subtotal_cents + (int) $order->tax_cents;

        if ($amount <= 0) {
            return;
        }

        try {
            $tenderId = $this->externalTenderId($merchantId, $accessToken);

            if ($tenderId === null) {
                Log::warning('Clover merchant has no external-payment tender; ticket left open', [
                    'order_id' => $order->id,
                    'merchant_id' => $merchantId,
                    'clover_order_id' => $ticketId,
                ]);

                return;
            }

            $response = $this->client->authed($accessToken)
                ->post("/v3/merchants/{$merchantId}/orders/{$ticketId}/payments", [
                    'tender' => ['id' => $tenderId],
                    'amount' => $amount,
                    'taxAmount' => (int) $order->tax_cents,
                    'tipAmount' => (int) $order->tip_cents,
                    'externalPaymentId' => $order->number,
                    'note' => 'Paid online via Plateful',
                ]);

            if ($response->failed()) {
                Log::warning('Clover payment record failed; ticket left open', [
                    'order_id' => $order->id,
                    'clover_order_id' => $ticketId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Clover payment record errored; ticket left open', [
                'order_id' => $order->id,
                'clover_order_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The merchant's "External payment" tender id, looked up once and cached.
     * Clover ships this system tender disabled and hidden by default — that only
     * governs the register's tender screen, and API-recorded payments still
     * accept it — so we match on the label key alone. Null only when the
     * merchant has removed it.
     */
    private function externalTenderId(string $merchantId, string $accessToken): ?string
    {
        $cacheKey = "clover.external_tender.{$merchantId}";

        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        $response = $this->client->authed($accessToken)
            ->get("/v3/merchants/{$merchantId}/tenders");

        if ($response->failed()) {
            throw PosProviderException::pushFailed('Clover tenders lookup failed: '.$response->body());
        }

        foreach ($response->json('elements', []) as $tender) {
            if (($tender['labelKey'] ?? null) === self::EXTERNAL_TENDER_LABEL_KEY
                && is_string($tender['id'] ?? null)) {
                Cache::put($cacheKey, $tender['id'], self::TENDER_CACHE_TTL_SECONDS);

                return $tender['id'];
            }
        }

        return null;
    }

    /**
     * Return a usable access token, refreshing proactively if the stored one is
     * expired or within the refresh window. Clover access tokens live only ~30
     * minutes and each refresh rotates BOTH tokens, so we persist the new pair.
     * A missing refresh token means the merchant must reconnect.
     */
    private function freshAccessToken(PosIntegration $integration): string
    {
        $expiresAt = $integration->token_expires_at;

        if ($expiresAt !== null && $expiresAt->isAfter(now()->addMinutes(5))) {
            return (string) $integration->access_token;
        }

        if ($integration->refresh_token === null) {
            throw PosTokenExpiredException::for(PosProviderName::Clover);
        }

        $tokens = $this->oauth->refreshToken($integration->refresh_token);

        $integration->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt,
        ])->save();

        return $tokens->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderCart(Order $order): array
    {
        return [
            'note' => $this->orderNote($order),
            'lineItems' => $order->items
                ->flatMap(fn (OrderItem $item): array => $this->buildLineItems($item))
                ->all(),
        ];
    }

    /**
     * The ticket header the kitchen and counter read: order number, who is
     * picking up, pickup vs delivery, and any order-level kitchen notes.
     */
    private function orderNote(Order $order): string
    {
        $parts = ['Plateful #'.$order->number];

        if (filled($order->customer_name)) {
            $parts[] = trim((string) $order->customer_name);
        }

        if ($order->type !== null) {
            $parts[] = ucfirst($order->type->value);
        }

        $note = implode(' · ', $parts);

        if (filled($order->notes)) {
            $note .= ' — '.trim((string) $order->notes);
        }

        return substr($note, 0, self::NOTE_LIMIT);
    }

    /**
     * Expand one order line into N identical Clover line items (Clover groups
     * and counts them on the register), each priced at the unit price with the
     * selected options and the customer's special instructions folded into a
     * note.
     *
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(OrderItem $item): array
    {
        $line = [
            'name' => $item->name,
            'price' => (int) $item->unit_price_cents,
        ];

        $note = $this->lineNote($item);

        if ($note !== null) {
            $line['note'] = $note;
        }

        return array_fill(0, max(1, (int) $item->quantity), $line);
    }

    /**
     * Selected options as a comma-separated list (the v1 text-fallback for
     * modifiers), followed by the customer's per-line instructions, capped at
     * Clover's note limit.
     */
    private function lineNote(OrderItem $item): ?string
    {
        $parts = [];

        $modifiers = $item->modifiers;

        if (is_array($modifiers)) {
            foreach ($modifiers['groups'] ?? [] as $group) {
                foreach ($group['selections'] ?? [] as $selection) {
                    if (isset($selection['option_name'])) {
                        $parts[] = (string) $selection['option_name'];
                    }
                }
            }
        }

        $options = $parts === [] ? null : implode(', ', $parts);
        $instructions = filled($item->notes) ? trim((string) $item->notes) : null;

        $note = implode(' — ', array_filter([$options, $instructions]));

        if ($note === '') {
            return null;
        }

        return substr($note, 0, self::NOTE_LIMIT);
    }
}
