<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DeliveryMode: string
{
    case SelfDelivery = 'self';
    case ThirdParty = 'third_party';

    public function label(): string
    {
        return match ($this) {
            self::SelfDelivery => 'My own drivers',
            self::ThirdParty => 'A courier network (Uber Direct)',
        };
    }
}
