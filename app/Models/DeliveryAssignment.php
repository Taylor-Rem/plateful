<?php

namespace App\Models;

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAssignment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider' => DeliveryProviderName::class,
            'status' => DeliveryStatus::class,
            'quote_fee_cents' => 'integer',
            'actual_fee_cents' => 'integer',
            'pickup_eta_at' => 'datetime',
            'dropoff_eta_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
