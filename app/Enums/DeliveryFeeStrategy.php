<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DeliveryFeeStrategy: string
{
    case PassThrough = 'pass_through';
    case Absorb = 'absorb';
    case Split = 'split';
}
