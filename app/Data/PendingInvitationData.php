<?php

namespace App\Data;

use App\Models\AdminInvitation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PendingInvitationData extends Data
{
    public function __construct(
        public int $id,
        public string $email,
        public ?string $expiresAt,
        public ?string $invitedByName,
    ) {}

    public static function fromModel(AdminInvitation $invitation): self
    {
        return new self(
            id: $invitation->id,
            email: $invitation->email,
            expiresAt: $invitation->expires_at?->toIso8601String(),
            invitedByName: $invitation->invitedBy?->name,
        );
    }
}
