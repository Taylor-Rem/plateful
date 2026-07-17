<?php

namespace App\Models;

use App\Enums\DeliveryMode;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentState;
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
            'platform_commission_cents' => 'integer',
            'delivery_margin_cents' => 'integer',
            'courier_fee_cents' => 'integer',
            'total_cents' => 'integer',
            'awarded_loyalty_points' => 'integer',
            'refunded_at' => 'datetime',
            'refunded_cents' => 'integer',
            'delivery_address' => 'array',
            'payment_state' => PaymentState::class,
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'voided_at' => 'datetime',
            'pos_provider' => PosProviderName::class,
            'pos_pushed_at' => 'datetime',
            'pos_push_failed_at' => 'datetime',
        ];
    }

    /**
     * Whether fulfilling this order depends on a courier we don't employ.
     *
     * This is the line auth/capture is drawn on: a pickup order or one our own
     * driver takes is knowable at checkout, so it charges immediately. A
     * courier-network delivery is not — Uber only looks for a driver after the
     * delivery is created — so it is authorized and captured later.
     */
    public function requiresCourier(): bool
    {
        if ($this->type !== OrderType::Delivery) {
            return false;
        }

        $restaurant = $this->relationLoaded('restaurant')
            ? $this->getRelation('restaurant')
            : $this->restaurant()->first();

        // A null mode means third-party to the dispatcher, so it must mean
        // third-party here too or the two disagree about the same order.
        return $restaurant !== null
            && $restaurant->delivery_mode !== DeliveryMode::SelfDelivery;
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

    /**
     * The quote this order's delivery fee was priced from, if any.
     *
     * Not a relation: it is looked up unscoped because the dispatch job runs in
     * a queue worker with no tenant bound, and joined on an opaque token rather
     * than an id so the handle can safely travel through the browser.
     */
    public function deliveryQuote(): ?DeliveryQuote
    {
        if ($this->delivery_quote_token === null) {
            return null;
        }

        return DeliveryQuote::withoutTenantScope()
            ->where('token', $this->delivery_quote_token)
            ->first();
    }
}
