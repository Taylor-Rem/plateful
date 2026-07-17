<?php

namespace App\Services\Delivery;

/**
 * The outcome of asking a delivery provider to cancel a dispatched delivery
 * (DoorDash plan Session 5).
 *
 * The only thing the refund math needs to know is how much of the courier fee
 * the provider is giving back. Under DoorDash's central billing Plateful has
 * already paid the courier, so this decides whether the customer's delivery
 * line can be refunded without Plateful eating the cost:
 *
 *   - Pre-pickup: DoorDash charges nothing → `courierFeeChargedCents = 0` → the
 *     whole delivery line is recoverable, so the customer gets it back.
 *   - Post-pickup: DoorDash keeps the fee → `courierFeeChargedCents = D` → the
 *     delivery already happened, so the customer keeps being charged for it.
 *
 * For a pass-through provider (Uber) or self-delivery Plateful never fronted a
 * courier fee, so `courierFeeChargedCents` is 0 and the delivery line follows
 * the ordinary food-refund policy.
 */
class DeliveryCancellation
{
    public function __construct(
        public readonly int $courierFeeChargedCents = 0,
    ) {}

    /**
     * Nothing was charged for the cancellation — the courier fee is fully
     * recoverable (pre-pickup, or a provider Plateful never paid directly).
     */
    public static function fullyRefunded(): self
    {
        return new self(courierFeeChargedCents: 0);
    }

    /**
     * The provider kept the courier fee (post-pickup). The delivery line is not
     * recoverable, so the customer's delivery fee is not refunded.
     */
    public static function courierFeeRetained(int $chargedCents): self
    {
        return new self(courierFeeChargedCents: max(0, $chargedCents));
    }

    /**
     * Whether the courier fee came back in full, making the delivery line safe
     * to refund to the customer.
     */
    public function courierFeeRecoverable(): bool
    {
        return $this->courierFeeChargedCents <= 0;
    }
}
