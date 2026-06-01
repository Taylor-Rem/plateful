<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingCheckout extends Model
{
    protected $guarded = [];

    public const STATUS_AWAITING = 'awaiting_payment';

    public const STATUS_CONSUMED = 'consumed';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
