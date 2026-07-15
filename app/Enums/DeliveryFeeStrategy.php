<?php

namespace App\Enums;

use App\Models\Restaurant;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How the courier's fee reaches the customer's bill.
 *
 * Two cases, because there are two products. A former `Split` case was dropped:
 * it implied a splitting rule this app never defined, and — like
 * `customer_delivery_fee_cents_max` alongside it — nothing ever read it. If an
 * exposure cap ever ships, that is the moment a third case means something and
 * can be added with a rule behind it.
 */
#[TypeScript]
enum DeliveryFeeStrategy: string
{
    /** The customer pays the live quote. The fee is what delivery costs. */
    case PassThrough = 'pass_through';

    /** The restaurant advertises a flat fee and absorbs the difference. */
    case Absorb = 'absorb';

    public function label(): string
    {
        return match ($this) {
            self::PassThrough => 'Pass the real cost to the customer',
            self::Absorb => 'Charge a flat fee and absorb the difference',
        };
    }

    /**
     * What the customer is charged for delivery, given what the provider quoted.
     *
     * Under `Absorb` the advertised fee is the customer's price no matter what
     * the courier costs — including free delivery, which is just
     * `delivery_fee_cents = 0` down this same path rather than a third case.
     */
    public function customerFeeCents(int $quotedFeeCents, Restaurant $restaurant): int
    {
        return match ($this) {
            self::PassThrough => max(0, $quotedFeeCents),
            self::Absorb => max(0, (int) $restaurant->delivery_fee_cents),
        };
    }

    /**
     * Whether the customer's price can move when we re-quote. Drives the
     * checkout countdown: under `Absorb` the price is fixed, so there is nothing
     * to count down and we re-quote silently instead.
     */
    public function quoteIsCustomerVisible(): bool
    {
        return $this === self::PassThrough;
    }
}
