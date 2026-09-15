<?php

namespace App\Data;

use App\Models\ApiKey;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An API key as listed: the handle, never the secret. `ApiKeyCreatedData`
 * carries the plaintext exactly once, on creation.
 */
#[TypeScript]
class ApiKeyData extends Data
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $keyPrefix,
        public array $scopes,
        public bool $isPlatform,
        /** Effective requests per minute: the key's own ceiling or the platform default. */
        public int $rateLimitPerMinute,
        public ?string $lastUsedAt,
        public ?string $expiresAt,
        public ?string $revokedAt,
        public ?string $createdByName,
        public string $createdAt,
    ) {}

    public static function fromModel(ApiKey $key): self
    {
        return new self(
            id: $key->id,
            name: $key->name,
            keyPrefix: $key->key_prefix,
            scopes: array_values((array) $key->scopes),
            isPlatform: $key->isPlatform(),
            rateLimitPerMinute: $key->rateLimitPerMinute(),
            lastUsedAt: $key->last_used_at?->toIso8601String(),
            expiresAt: $key->expires_at?->toIso8601String(),
            revokedAt: $key->revoked_at?->toIso8601String(),
            createdByName: $key->createdBy?->name,
            createdAt: $key->created_at->toIso8601String(),
        );
    }
}
