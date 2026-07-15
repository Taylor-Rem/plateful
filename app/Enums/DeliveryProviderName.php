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
}
