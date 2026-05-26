<?php

namespace App\Models;

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
