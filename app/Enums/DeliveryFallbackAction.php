<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DeliveryFallbackAction: string
{
    case TryNextProvider = 'try_next_provider';
    case HoldForOwner = 'hold_for_owner';
    case AutoCancelRefund = 'auto_cancel_refund';
}
