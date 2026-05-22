<?php

namespace App\Models;

use App\Services\RestaurantImageService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Restaurant extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'application_fee_percent' => 'decimal:2',
            'tax_rate_percent' => 'decimal:2',
            'delivery_fee_cents' => 'integer',
        ];
    }

    public function logoUrl(): ?string
    {
        return $this->variantUrl($this->logo_path, null);
    }

    public function logoMediumUrl(): ?string
    {
        return $this->variantUrl($this->logo_path, 'medium');
    }

    public function logoThumbUrl(): ?string
    {
        return $this->variantUrl($this->logo_path, 'thumb');
    }

    protected function variantUrl(?string $basePath, ?string $variant): ?string
    {
        if (! $basePath) {
            return null;
        }

        $path = $basePath;

        if ($variant !== null) {
            $dir = trim((string) Str::beforeLast($basePath, '/'), '/');
            $name = Str::beforeLast(Str::afterLast($basePath, '/'), '.');
            $prefix = $dir === '' ? '' : $dir.'/';
            $path = "{$prefix}{$name}-{$variant}.webp";
        }

        return Storage::disk(RestaurantImageService::DISK)->url($path);
    }

    public function publicUrl(string $scheme = 'https'): string
    {
        $host = $this->custom_domain
            ?: $this->subdomain.'.'.config('platform.primary_domain');

        return $scheme.'://'.$host;
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'restaurant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function admins(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'admin');
    }

    public function staff(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'staff');
    }

    public function hours(): HasMany
    {
        return $this->hasMany(RestaurantHour::class)
            ->orderBy('day_of_week')
            ->orderBy('position');
    }

    /**
     * Is this restaurant currently open at the given moment (or now())?
     * Restaurants with NO hour rows configured are treated as always open
     * (backward compat for legacy data).
     */
    public function isOpenAt(?CarbonImmutable $when = null): bool
    {
        $tz = $this->timezone ?: 'America/New_York';
        $moment = ($when ?? CarbonImmutable::now())->setTimezone($tz);

        $all = $this->hours()->get();
        if ($all->isEmpty()) {
            return true;
        }

        $todayDow = (int) $moment->dayOfWeek;
        $yesterdayDow = ($todayDow + 6) % 7;
        $time = $moment->format('H:i:s');

        // Today's windows
        foreach ($all->where('day_of_week', $todayDow) as $h) {
            $opens = $h->opens_at;
            $closes = $h->closes_at;

            if ($closes > $opens) {
                if ($time >= $opens && $time < $closes) {
                    return true;
                }
            } else {
                // Crosses midnight: window covers from opens_at to end-of-day.
                if ($time >= $opens) {
                    return true;
                }
            }
        }

        // Yesterday's midnight-crossing windows that extend into today.
        foreach ($all->where('day_of_week', $yesterdayDow) as $h) {
            $opens = $h->opens_at;
            $closes = $h->closes_at;

            if ($closes <= $opens && $time < $closes) {
                return true;
            }
        }

        return false;
    }

    /**
     * Next moment the restaurant opens. Returns the requested time if currently open.
     * Returns null if there are NO hour rows (always-open fallback).
     */
    public function nextOpenAt(?CarbonImmutable $when = null): ?CarbonImmutable
    {
        $tz = $this->timezone ?: 'America/New_York';
        $moment = ($when ?? CarbonImmutable::now())->setTimezone($tz);

        $all = $this->hours()->get();
        if ($all->isEmpty()) {
            return null;
        }

        if ($this->isOpenAt($moment)) {
            return $moment;
        }

        $byDay = $all->groupBy('day_of_week');

        // Today: maybe a window opens later today.
        $todayDow = (int) $moment->dayOfWeek;
        $time = $moment->format('H:i:s');

        $todayWindows = ($byDay[$todayDow] ?? collect())->sortBy('opens_at')->values();
        foreach ($todayWindows as $h) {
            if ($h->opens_at > $time) {
                [$hh, $mm, $ss] = array_pad(explode(':', $h->opens_at), 3, '00');

                return $moment->setTime((int) $hh, (int) $mm, (int) $ss);
            }
        }

        // Look ahead 1..7 days.
        for ($i = 1; $i <= 7; $i++) {
            $future = $moment->addDays($i);
            $dow = (int) $future->dayOfWeek;
            $windows = ($byDay[$dow] ?? collect())->sortBy('opens_at')->values();
            if ($windows->isEmpty()) {
                continue;
            }
            $first = $windows->first();
            [$hh, $mm, $ss] = array_pad(explode(':', $first->opens_at), 3, '00');

            return $future->setTime((int) $hh, (int) $mm, (int) $ss);
        }

        return null;
    }

    /**
     * Human-friendly "Opens at ..." label. Returns null when currently open
     * or when no hours are configured.
     */
    public function formatNextOpenAt(?CarbonImmutable $when = null): ?string
    {
        $tz = $this->timezone ?: 'America/New_York';
        $moment = ($when ?? CarbonImmutable::now())->setTimezone($tz);

        if ($this->isOpenAt($moment)) {
            return null;
        }

        $next = $this->nextOpenAt($moment);
        if (! $next) {
            return null;
        }

        $timeStr = $next->format('g:i A');

        if ($next->isSameDay($moment)) {
            return "Opens at {$timeStr} today";
        }
        if ($next->isSameDay($moment->addDay())) {
            return "Opens tomorrow at {$timeStr}";
        }

        return 'Opens '.$next->format('l').' at '.$timeStr;
    }
}
