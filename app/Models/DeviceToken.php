<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone that can receive push for one user. Tokens are Expo push tokens
 * (`ExponentPushToken[...]`); a token moves with the device, so registering
 * one that another account holds re-homes it rather than duplicating it.
 */
class DeviceToken extends Model
{
    public const PROVIDER_EXPO = 'expo';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
