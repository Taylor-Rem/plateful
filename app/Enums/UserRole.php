<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum UserRole: string
{
    case Customer = 'customer';
    case Admin = 'admin';
}
