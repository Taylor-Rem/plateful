<?php

namespace App\Data;

use App\Enums\SocialProvider;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The signed-in account as the mobile app sees it (GET /api/v1/me). Part of
 * the v1 API contract: fields are additive-only from here on.
 */
#[TypeScript]
class MeData extends Data
{
    /**
     * @param  array<int, string>  $linkedProviders
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $avatar,
        public bool $emailVerified,
        public bool $twoFactorEnabled,
        public array $linkedProviders,
        public string $createdAt,
        /** Push notifications for order milestones (default on). */
        public bool $pushOrderUpdates = true,
    ) {}

    public static function fromModel(User $user): self
    {
        $linked = [];

        foreach (SocialProvider::cases() as $provider) {
            if (filled($user->{$provider->identifierColumn()})) {
                $linked[] = $provider->value;
            }
        }

        return new self(
            id: $user->id,
            name: (string) $user->name,
            email: (string) $user->email,
            phone: $user->phone,
            avatar: $user->avatar,
            emailVerified: $user->email_verified_at !== null,
            twoFactorEnabled: $user->hasEnabledTwoFactorAuthentication(),
            linkedProviders: $linked,
            createdAt: $user->created_at->toIso8601String(),
            pushOrderUpdates: (bool) ($user->push_order_updates ?? true),
        );
    }
}
