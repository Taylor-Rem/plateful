<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantHour extends Model
{
    protected $fillable = [
        'restaurant_id',
        'day_of_week',
        'opens_at',
        'closes_at',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'position' => 'integer',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Normalize opens_at to "HH:MM:SS".
     */
    public function getOpensAtAttribute(?string $value): ?string
    {
        return self::normalizeTime($value);
    }

    public function getClosesAtAttribute(?string $value): ?string
    {
        return self::normalizeTime($value);
    }

    public static function normalizeTime(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Some DB drivers may return "HH:MM:SS" already, others might return a
        // full datetime string. Just take the time portion.
        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m) === 1) {
            $h = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mn = $m[2];
            $s = $m[3] ?? '00';

            return "$h:$mn:$s";
        }

        return $value;
    }
}
