<?php

namespace App\Models;

use App\Enums\RevenueRole;
use Database\Factories\FeeDistributionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One earned slice of an order's retained platform fee. Immutable once
 * written; see the fee_distributions migration for the snapshot rationale.
 */
class FeeDistribution extends Model
{
    /** @use HasFactory<FeeDistributionFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'restaurant_id',
        'user_id',
        'role',
        'percent',
        'amount_cents',
        'earned_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => RevenueRole::class,
            'percent' => 'decimal:2',
            'amount_cents' => 'integer',
            'earned_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
