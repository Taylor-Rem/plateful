<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DeliveryProviderName: string
{
    case Self = 'self';
    case DoorDash = 'doordash';
    case Uber = 'uber';
}
