<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\DeliveryFeeStrategy;
use App\Http\Controllers\Controller;
use App\Models\DeliveryQuote;
use App\Services\CartManager;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DeliveryMarkup;
use App\Services\Delivery\DeliveryQuoteRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Quotes delivery for an address, before the customer pays.
 *
 * This is the inversion the whole plan is built around: today the quote happens
 * post-payment inside DispatchDeliveryForOrder, which is exactly why a customer
 * can be charged before we know delivery is even possible. Here the quote gates
 * checkout instead of trailing it.
 *
 * A failed quote is the out-of-range check, for free — no geocoding, no radius
 * maths, no delivery-zone table. Uber caps around 10 miles but varies it by
 * market and driver density, so there is no constant worth hardcoding: let the
 * quote be the oracle.
 */
class DeliveryQuoteController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentTenant $tenant,
        CartManager $carts,
        DeliveryDispatcher $dispatcher,
    ): JsonResponse {
        $validated = $request->validate([
            'address.street' => ['required', 'string', 'max:255'],
            'address.street2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'max:255'],
            'address.state' => ['required', 'string', 'max:32'],
            'address.postal_code' => ['required', 'string', 'max:20'],
            'address.country' => ['nullable', 'string', 'max:64'],
            'address.instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        $restaurant = $tenant->get();

        if (! $restaurant->delivery_enabled) {
            return response()->json([
                'message' => $restaurant->name.' doesn’t offer delivery.',
            ], 422);
        }

        $address = $this->normalizeAddress($validated['address']);
        $cart = $carts->current();

        try {
            $quote = $dispatcher->quote(new DeliveryQuoteRequest(
                restaurant: $restaurant,
                dropoffAddress: $address,
                subtotalCents: (int) ($cart?->items->sum(fn ($line) => $line->unit_price_cents * $line->quantity) ?? 0),
                tipCents: 0,
                customerName: $request->user()?->name,
                customerPhone: null,
            ));
        } catch (Throwable $e) {
            // Every failure reads the same to the customer on purpose: out of
            // range, no couriers, and a provider outage are all "not right now",
            // and the difference is not theirs to act on.
            report($e);

            return response()->json([
                'message' => 'We can’t deliver to that address right now. You can still choose pickup.',
            ], 422);
        }

        $record = DeliveryQuote::record($restaurant, $quote, $address);

        $strategy = $restaurant->delivery_fee_strategy ?? DeliveryFeeStrategy::PassThrough;

        // A centrally-billed provider (DoorDash) grosses the courier cost up so
        // the restaurant bears no Stripe fee on delivery; the strategy does not
        // apply (third-party is always pass-through-with-markup, plan §4b). A
        // pass-through provider (Uber) keeps the restaurant's own strategy.
        $centrallyBilled = $quote->provider->isCentrallyBilled();
        $customerFeeCents = $centrallyBilled
            ? DeliveryMarkup::customerFeeCents($quote->feeCents, (float) $restaurant->application_fee_percent)
            : $strategy->customerFeeCents($quote->feeCents, $restaurant);

        return response()->json([
            'quote' => [
                'token' => $record->token,
                // What the customer pays — not necessarily what the courier
                // costs. Under Absorb the restaurant eats the difference.
                'feeCents' => $customerFeeCents,
                // The provider's ETA assumes the food is ready now, so the
                // kitchen's prep time has to be added or the promise is wrong by
                // the length of the ticket.
                'etaMinutes' => $quote->etaMinutes === null
                    ? null
                    : $quote->etaMinutes + (int) $restaurant->prep_time_minutes,
                // Show a countdown whenever the customer's price can move on a
                // re-quote: always under central billing, and under pass-through
                // for a per-restaurant provider. Absorb re-quotes silently.
                'expiresAt' => $centrallyBilled || $strategy->quoteIsCustomerVisible()
                    ? $record->expires_at?->toIso8601String()
                    : null,
            ],
        ]);
    }

    /**
     * One canonical shape, built once. Both the quote and the order snapshot
     * come from here so the address Uber is asked about is the address it is
     * later told to drive to.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, string|null>
     */
    private function normalizeAddress(array $address): array
    {
        return [
            'street' => (string) ($address['street'] ?? ''),
            'street2' => isset($address['street2']) ? (string) $address['street2'] : null,
            'city' => (string) ($address['city'] ?? ''),
            'state' => (string) ($address['state'] ?? ''),
            'postal_code' => (string) ($address['postal_code'] ?? ''),
            'country' => (string) ($address['country'] ?? 'US') ?: 'US',
            'instructions' => isset($address['instructions']) ? (string) $address['instructions'] : null,
        ];
    }
}
