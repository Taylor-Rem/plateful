<?php

namespace App\Models;

use App\Enums\CampaignRecipientStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One mailed (or to-be-mailed) inbox of a campaign — the audit record of
 * exactly who was contacted. email is snapshotted at send time; the row is
 * never re-pointed when the user later changes their address.
 */
class CampaignRecipient extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CampaignRecipientStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
