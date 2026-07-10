<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PosProviderName;
use App\Enums\TipRecipient;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'type' => OrderType::class,
            'tip_recipient' => TipRecipient::class,
            'placed_at' => 'datetime',
            'pickup_ready_at' => 'datetime',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'tip_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'application_fee_cents' => 'integer',
            'total_cents' => 'integer',
            'awarded_loyalty_points' => 'integer',
            'refunded_at' => 'datetime',
            'refunded_cents' => 'integer',
            'delivery_address' => 'array',
            'pos_provider' => PosProviderName::class,
            'pos_pushed_at' => 'datetime',
            'pos_push_failed_at' => 'datetime',
        ];
    }

    public static function generateNumber(Restaurant $restaurant): string
    {
        $prefix = strtoupper(substr($restaurant->subdomain, 0, 3));
        if (strlen($prefix) < 3) {
            $prefix = str_pad($prefix, 3, 'X');
        }

        return $prefix.'-'.Str::upper(Str::random(5));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function deliveryAssignment(): BelongsTo
    {
        return $this->belongsTo(DeliveryAssignment::class);
    }
}
