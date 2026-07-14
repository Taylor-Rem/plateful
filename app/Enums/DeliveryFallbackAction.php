<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DeliveryFallbackAction: string
{
    case TryNextProvider = 'try_next_provider';
    case HoldForOwner = 'hold_for_owner';
    case AutoCancelRefund = 'auto_cancel_refund';

    public function label(): string
    {
        return match ($this) {
            self::TryNextProvider => 'Try the next courier network',
            self::HoldForOwner => 'Hold the order for me to sort out',
            self::AutoCancelRefund => 'Cancel and refund the customer',
        };
    }
}
