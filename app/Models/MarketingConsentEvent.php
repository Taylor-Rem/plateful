<?php

namespace App\Models;

use App\Enums\MarketingChannel;
use App\Enums\MarketingConsentAction;
use App\Enums\MarketingConsentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit record of a marketing consent change. Rows are the legal
 * proof of consent (consent_text_snapshot is the exact label the customer
 * saw) — never updated or deleted, only appended.
 */
class MarketingConsentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel' => MarketingChannel::class,
            'action' => MarketingConsentAction::class,
            'source' => MarketingConsentSource::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
