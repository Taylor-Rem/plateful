<?php

namespace App\Models;

use App\Enums\ApiKeyScope;
use Database\Factories\ApiKeyFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A machine credential for the operator API and the MCP server. Restaurant
 * keys act for one restaurant within their scopes; platform keys
 * (restaurant_id null) act for every restaurant like a super admin.
 *
 * The key is an Authenticatable so the `api-key` guard can return it as the
 * request's principal; App\Support\Api\ApiActor wraps whichever principal
 * signed the request and answers the access questions.
 */
class ApiKey extends Model implements Authenticatable
{
    /** @use HasFactory<ApiKeyFactory> */
    use AuthenticatableTrait, HasFactory;

    public const PREFIX_LIVE = 'pfk_live_';

    public const PREFIX_TEST = 'pfk_test_';

    /**
     * Rows are touched at most this often so a busy key does not write on
     * every request.
     */
    protected const TOUCH_INTERVAL_SECONDS = 60;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Mint a key. The returned plaintext is the only time it is readable.
     *
     * @param  array<int, ApiKeyScope>  $scopes
     * @return array{key: self, plainTextKey: string}
     */
    public static function mint(
        string $name,
        array $scopes,
        ?Restaurant $restaurant = null,
        ?User $createdBy = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $plain = static::prefix().Str::random(40);

        $key = static::query()->create([
            'restaurant_id' => $restaurant?->id,
            'name' => $name,
            'key_prefix' => static::visiblePrefix($plain),
            'key_hash' => static::hashKey($plain),
            'scopes' => array_values(array_unique(array_map(fn (ApiKeyScope $s) => $s->value, $scopes))),
            'expires_at' => $expiresAt,
            'created_by_user_id' => $createdBy?->id,
        ]);

        return ['key' => $key, 'plainTextKey' => $plain];
    }

    /**
     * Resolve a bearer value to a usable key, or null. Revoked and expired
     * keys are invisible here, so the guard treats them as unauthenticated.
     */
    public static function authenticate(?string $plainTextKey): ?self
    {
        if ($plainTextKey === null || $plainTextKey === '' || ! str_starts_with($plainTextKey, 'pfk_')) {
            return null;
        }

        $key = static::query()
            ->where('key_hash', static::hashKey($plainTextKey))
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        $key?->touchLastUsed();

        return $key;
    }

    public static function hashKey(string $plainTextKey): string
    {
        return hash('sha256', $plainTextKey);
    }

    public static function prefix(): string
    {
        return app()->isProduction() ? self::PREFIX_LIVE : self::PREFIX_TEST;
    }

    /**
     * The handle shown in lists: the environment prefix plus the first
     * characters of the secret, e.g. `pfk_live_a1B2c3D4`.
     */
    public static function visiblePrefix(string $plainTextKey): string
    {
        return substr($plainTextKey, 0, strlen(static::prefix()) + 8);
    }

    public function isPlatform(): bool
    {
        return $this->restaurant_id === null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasScope(ApiKeyScope $scope): bool
    {
        $scopes = (array) $this->scopes;

        return in_array(ApiKeyScope::All->value, $scopes, true)
            || in_array($scope->value, $scopes, true);
    }

    /**
     * @return array<int, ApiKeyScope>
     */
    public function scopeCases(): array
    {
        return array_values(array_filter(array_map(
            fn (string $value) => ApiKeyScope::tryFrom($value),
            (array) $this->scopes,
        )));
    }

    public function revoke(): void
    {
        if ($this->isRevoked()) {
            return;
        }

        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function touchLastUsed(): void
    {
        $recentlyTouched = $this->last_used_at !== null
            && $this->last_used_at->gt(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS));

        if ($recentlyTouched) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
