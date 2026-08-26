<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantCustomer extends Model
{
    protected $table = 'restaurant_customer';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_ordered_at' => 'datetime',
            'last_ordered_at' => 'datetime',
            'total_orders' => 'integer',
            'total_spent_cents' => 'integer',
            'marketing_email_opted_in_at' => 'datetime',
            'marketing_email_opted_out_at' => 'datetime',
        ];
    }

    /**
     * Consent-eligible for email marketing at this restaurant. Callers must
     * additionally exclude soft-deleted users (a join/whereHas concern).
     */
    public function isEmailOptedIn(): bool
    {
        return $this->marketing_email_opted_in_at !== null
            && $this->marketing_email_opted_out_at === null;
    }

    public function scopeEmailOptedIn(Builder $query): Builder
    {
        return $query
            ->whereNotNull('marketing_email_opted_in_at')
            ->whereNull('marketing_email_opted_out_at');
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
