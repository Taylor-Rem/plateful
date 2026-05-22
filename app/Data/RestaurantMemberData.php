<?php

namespace App\Data;

use App\Enums\RestaurantRole;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RestaurantMemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public RestaurantRole $role,
    ) {}

    public static function fromModel(User $user): self
    {
        $role = RestaurantRole::tryFrom((string) ($user->pivot->role ?? 'admin')) ?? RestaurantRole::Admin;

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $role,
        );
    }
}
