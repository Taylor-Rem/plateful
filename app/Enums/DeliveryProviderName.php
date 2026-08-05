<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DeliveryProviderName: string
{
    case Self = 'self';
    case DoorDash = 'doordash';
    case Uber = 'uber';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'Own drivers',
            self::DoorDash => 'DoorDash Drive',
            self::Uber => 'Uber Direct',
        };
    }

    /**
     * Whether Plateful is the payer of record for the courier — i.e. Plateful
     * pays the courier network and must recover the cost through the Stripe
     * application fee (DoorDash plan §1). Both courier networks run umbrella
     * models now: DoorDash bills the platform account for every store, and
     * Uber bills the root Direct account for every sub-organization
     * (BILLING_TYPE_CENTRALIZED — see UberDirectProvisioningService).
     * Self-delivery has no courier cost. This is what gates the customer
     * delivery-fee gross-up and the courier/margin accounting.
     *
     * Historical pass-through Uber orders are unaffected: the refund path keys
     * on `courier_fee_cents > 0`, which those orders never set.
     */
    public function isCentrallyBilled(): bool
    {
        return $this !== self::Self;
    }
}
