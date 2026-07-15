<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where an order's money actually is.
 *
 * Deliberately separate from {@see OrderStatus}, which is the *kitchen*
 * lifecycle (pending → confirmed → preparing → ready). "Authorized" is not a
 * thing a cook can do anything about, and folding it in would mean every
 * transition rule had to reason about payments.
 *
 * Most orders are `Captured` the instant they exist — pickup and self-delivery
 * have nothing to wait for. Only a courier-network delivery is authorized first
 * and captured once a courier is actually confirmed, which is the only moment
 * anyone can honestly say the delivery will happen.
 */
#[TypeScript]
enum PaymentState: string
{
    /** Money taken. The normal end state, and the only state for pickup. */
    case Captured = 'captured';

    /** A hold on the customer's card, awaiting a courier. Not yet money. */
    case Authorized = 'authorized';

    /** The hold was released. The customer is never charged. */
    case Voided = 'voided';

    /**
     * Whether this payment still has to be resolved one way or the other. An
     * authorized order that is never captured or voided is the worst outcome
     * here: the customer's funds sit held for days.
     */
    public function isPending(): bool
    {
        return $this === self::Authorized;
    }
}
