<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum OrderType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
}
