<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum AutoCancelRefundMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
