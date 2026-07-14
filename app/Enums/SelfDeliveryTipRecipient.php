<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SelfDeliveryTipRecipient: string
{
    case Driver = 'driver';
    case Pool = 'pool';
    case Split5050 = 'split_50_50';

    public function label(): string
    {
        return match ($this) {
            self::Driver => 'The driver who delivered it',
            self::Pool => 'The house tip pool',
            self::Split5050 => 'Split 50/50 between driver and pool',
        };
    }
}
