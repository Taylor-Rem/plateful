<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Log a status-neutral note on the order timeline (POS pushes, delivery
     * dispatch attempts). `to_status` is non-nullable, so the current status
     * is recorded on both sides.
     */
    public static function note(Order $order, string $note): self
    {
        return self::create([
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'occurred_at' => now(),
            'user_id' => null,
            'note' => $note,
        ]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
