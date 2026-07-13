<?php

namespace App\Services\Pos\Square;

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
 * Injects a paid Plateful order into the restaurant's Square account as a
 * Square Order. v1 is a text-fallback: each line is an ad-hoc line item priced
 * at what the customer actually paid, with selected options folded into a note.
 * Plateful stays the pricing authority; the Square order is for fulfillment,
 * not re-pricing. The guided catalog matcher (referencing real Square catalog
 * ids) is a later phase.
 */
class SquarePosProvider implements PosProvider
{
    public function __construct(
        private SquareClient $client,
        private SquareOAuthService $oauth,
    ) {}

    public function name(): PosProviderName
    {
        return PosProviderName::Square;
    }

    public function supports(Restaurant $restaurant): bool
    {
        // The connected integration is the real per-restaurant gate (checked by
        // the dispatcher); here we only guard against a totally unconfigured app.
        return config('services.square.application_id') !== null;
    }

    public function pushOrder(Order $order, PosIntegration $integration): PosPushResult
    {
        if ($integration->location_id === null) {
            throw PosProviderException::pushFailed('Square integration is missing a location id; reconnect required.');
        }

        $accessToken = $this->freshAccessToken($integration);

        $response = $this->client->authed($accessToken)->post('/v2/orders', [
            'idempotency_key' => 'pf-order-'.$order->id,
            'order' => $this->buildOrderPayload($order, $integration->location_id),
        ]);

        if ($response->status() === 401) {
            throw PosTokenExpiredException::for(PosProviderName::Square);
        }

        if ($response->failed()) {
            throw PosProviderException::pushFailed('Square order create failed: '.$response->body());
        }

        $ticketId = $response->json('order.id');

        if (! is_string($ticketId) || $ticketId === '') {
            throw PosProviderException::pushFailed('Square order create returned no order id.');
        }

        return PosPushResult::ok(PosProviderName::Square, $ticketId);
    }

    /**
     * Return a usable access token, refreshing proactively if the stored one is
     * expired or within the refresh window. A missing refresh token means the
     * merchant must reconnect.
     */
    private function freshAccessToken(PosIntegration $integration): string
    {
        $expiresAt = $integration->token_expires_at;

        if ($expiresAt !== null && $expiresAt->isAfter(now()->addMinutes(5))) {
            return (string) $integration->access_token;
        }

        if ($integration->refresh_token === null) {
            throw PosTokenExpiredException::for(PosProviderName::Square);
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
    private function buildOrderPayload(Order $order, string $locationId): array
    {
        return [
            'location_id' => $locationId,
            // reference_id (max 40) links back to us; ticket_name (max 30) is
            // what shows on the kitchen ticket / KDS.
            'reference_id' => substr($order->number, 0, 40),
            'ticket_name' => substr($order->number, 0, 30),
            'source' => ['name' => 'Plateful'],
            'line_items' => $order->items->map(fn (OrderItem $item): array => $this->buildLineItem($item))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLineItem(OrderItem $item): array
    {
        $line = [
            'name' => $item->name,
            'quantity' => (string) max(1, (int) $item->quantity),
            'base_price_money' => [
                'amount' => (int) $item->unit_price_cents,
                'currency' => 'USD',
            ],
        ];

        $note = $this->modifierNote($item);

        if ($note !== null) {
            $line['note'] = $note;
        }

        return $line;
    }

    /**
     * Fold the selected options into a comma-separated text note — the v1
     * text-fallback for modifiers, capped at Square's 500-char note limit.
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

        return substr(implode(', ', $parts), 0, 500);
    }
}
