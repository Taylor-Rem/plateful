<?php

namespace App\Models;

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Tenancy\BelongsToTenant;
use Database\Factories\DeliveryIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A restaurant's connection to a delivery provider. Both connectable providers
 * are umbrella / central-billing integrations authenticated by PLATFORM
 * credentials (config `services.*`), so the row stores only the provisioned
 * identifiers: `customer_id` (the Uber sub-organization id) or
 * `external_business_id`/`external_store_id` (DoorDash), plus status.
 *
 * The encrypted credential columns (`client_id`, `client_secret`,
 * `access_token`, `webhook_signing_key`, `token_expires_at`) are legacy from
 * the per-restaurant Uber model and are no longer written.
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
}
