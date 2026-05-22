<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SelfDeliveryTipRecipient: string
{
    case Driver = 'driver';
    case Pool = 'pool';
    case Split5050 = 'split_50_50';
}
