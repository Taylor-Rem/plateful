<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A restaurant's email campaign (campaigns plan, Phase 3). The structured
 * fields (headline/body/callout/CTA) are the only restaurant-authored content;
 * the compliance footer is platform-rendered. audience_filter is a recipe, not
 * a list — it resolves to concrete recipients only when SendCampaign runs.
 */
class Campaign extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'audience_filter' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'recipients_count' => 'integer',
            'delivered_count' => 'integer',
            'bounced_count' => 'integer',
            'complained_count' => 'integer',
            'unsubscribed_count' => 'integer',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
