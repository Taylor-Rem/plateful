<?php

namespace App\Models;

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Tenancy\BelongsToTenant;
use Database\Factories\DeliveryIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-restaurant delivery-provider credentials. Uber Direct is a
 * `client_credentials` integration, so unlike {@see PosIntegration} the
 * restaurant's own `client_id`/`client_secret` are stored long-term rather than
 * exchanged once for a rotating refresh token — they are what mints every
 * access token from here on.
 *
 * Credentials are encrypted at rest via the `encrypted` cast — rotating APP_KEY
 * invalidates them and the restaurant must re-enter them.
 */
class DeliveryIntegration extends Model
{
    /** @use HasFactory<DeliveryIntegrationFactory> */
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider' => DeliveryProviderName::class,
            'status' => DeliveryIntegrationStatus::class,
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'webhook_signing_key' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * Whether this integration holds everything needed to mint a token. Status
     * is a separate question — credentials can be complete but rejected.
     */
    public function hasCredentials(): bool
    {
        return $this->client_id !== null
            && $this->client_secret !== null
            && $this->customer_id !== null;
    }
}
