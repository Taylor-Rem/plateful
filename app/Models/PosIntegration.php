<?php

namespace App\Models;

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Tenancy\BelongsToTenant;
use Database\Factories\PosIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-restaurant POS credentials. Tokens are encrypted at rest via the
 * `encrypted` cast — rotating APP_KEY invalidates them and the restaurant
 * must reconnect its POS.
 */
class PosIntegration extends Model
{
    /** @use HasFactory<PosIntegrationFactory> */
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider' => PosProviderName::class,
            'status' => PosIntegrationStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }
}
