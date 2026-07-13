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
 */
class CloverPosProvider implements PosProvider
{
    /**
     * Clover's line-item note cap; selected options beyond this are truncated.
     */
    private const NOTE_LIMIT = 500;

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

        return PosPushResult::ok(PosProviderName::Clover, $ticketId);
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
            'note' => 'Plateful #'.$order->number,
            'lineItems' => $order->items
                ->flatMap(fn (OrderItem $item): array => $this->buildLineItems($item))
                ->all(),
        ];
    }

    /**
     * Expand one order line into N identical Clover line items (Clover groups
     * and counts them on the register), each priced at the unit price with the
     * selected options folded into a note.
     *
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(OrderItem $item): array
    {
        $line = [
            'name' => $item->name,
            'price' => (int) $item->unit_price_cents,
        ];

        $note = $this->modifierNote($item);

        if ($note !== null) {
            $line['note'] = $note;
        }

        return array_fill(0, max(1, (int) $item->quantity), $line);
    }

    /**
     * Fold the selected options into a comma-separated text note — the v1
     * text-fallback for modifiers, capped at Clover's note limit.
     */
    private function modifierNote(OrderItem $item): ?string
    {
        $modifiers = $item->modifiers;

        if (! is_array($modifiers) || empty($modifiers['groups'])) {
            return null;
        }

        $parts = [];

        foreach ($modifiers['groups'] as $group) {
            foreach ($group['selections'] ?? [] as $selection) {
                if (isset($selection['option_name'])) {
                    $parts[] = (string) $selection['option_name'];
                }
            }
        }

        if ($parts === []) {
            return null;
        }

        return substr(implode(', ', $parts), 0, self::NOTE_LIMIT);
    }
}
