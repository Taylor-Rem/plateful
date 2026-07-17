<?php

namespace App\Services\Refunds;

/**
 * What to refund when a paid order is cancelled (DoorDash plan Session 5).
 *
 * Two amounts move in opposite directions on a direct charge:
 *   - `customerRefundCents` is debited from the restaurant's connected account
 *     back to the customer.
 *   - `applicationFeeReversalCents` is returned from Plateful's platform account
 *     to the restaurant's account to fund the part of that refund that came out
 *     of Plateful's fee (commission, and — under central billing — the courier
 *     passthrough Plateful recovers from DoorDash).
 *
 * The two flags say which slices of Plateful's revenue were reversed, so the
 * caller can zero the matching order columns and delete the matching ledger rows
 * — keeping month-to-date commission and the earnings split honest.
 */
class RefundPlan
{
    public function __construct(
        public readonly int $customerRefundCents,
        public readonly int $applicationFeeReversalCents,
        public readonly bool $reverseCommission,
        public readonly bool $reverseMargin,
        public readonly bool $isFullRefund,
    ) {}

    /**
     * Nothing is refundable (policy off and nothing recoverable). The order
     * still cancels; no money moves.
     */
    public static function none(): self
    {
        return new self(
            customerRefundCents: 0,
            applicationFeeReversalCents: 0,
            reverseCommission: false,
            reverseMargin: false,
            isFullRefund: false,
        );
    }

    public function refundsAnything(): bool
    {
        return $this->customerRefundCents > 0;
    }
}
