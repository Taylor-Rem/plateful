<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum PosProviderName: string
{
    case Square = 'square';
    case Clover = 'clover';

    public function label(): string
    {
        return match ($this) {
            self::Square => 'Square',
            self::Clover => 'Clover',
        };
    }
}
