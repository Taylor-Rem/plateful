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
     * application fee (DoorDash plan §1). Only DoorDash Drive's umbrella model
     * works this way; Uber Direct bills each restaurant directly, and
     * self-delivery has no courier cost. This is what gates the customer
     * delivery-fee gross-up and the courier/margin accounting: a pass-through
     * provider needs neither.
     */
    public function isCentrallyBilled(): bool
    {
        return $this === self::DoorDash;
    }
}
