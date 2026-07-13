<?php

namespace App\Models;

use App\Enums\RevenueRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binds a platform-wide revenue role (Founder / Operator) to the single user
 * who currently holds it. Assigning a new holder is an upsert on `role`.
 */
class PlatformRoleHolder extends Model
{
    protected $fillable = ['role', 'user_id'];

    protected function casts(): array
    {
        return [
            'role' => RevenueRole::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user who currently holds the given platform role, or null if unset.
     */
    public static function holder(RevenueRole $role): ?User
    {
        return static::query()->where('role', $role->value)->first()?->user;
    }

    /**
     * Set (upsert) the holder of a platform role.
     */
    public static function assign(RevenueRole $role, User $user): self
    {
        return static::query()->updateOrCreate(
            ['role' => $role->value],
            ['user_id' => $user->id],
        );
    }
}
