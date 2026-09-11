<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the app needs to present Stripe's PaymentSheet for a prepared
 * checkout. The totals are the server's — the fee, tax and delivery math ran
 * in OrderPlacement::prepare() and nothing here came from the client.
 */
#[TypeScript]
class CheckoutIntentData extends Data
{
    public function __construct(
        public int $pendingCheckoutId,
        public string $paymentIntentId,
        public string $clientSecret,
        /** Platform publishable key; the sheet is initialised with it plus stripeAccountId (direct charge). */
        public string $publishableKey,
        public string $stripeAccountId,
        /** True for courier delivery: the card is held, not charged, until a courier is confirmed. */
        public bool $manualCapture,
        public int $subtotalCents,
        public int $taxCents,
        public int $deliveryFeeCents,
        public int $tipCents,
        public int $totalCents,
    ) {}
}
