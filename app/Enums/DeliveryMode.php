<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DeliveryMode: string
{
    case SelfDelivery = 'self';
    case ThirdParty = 'third_party';
}
