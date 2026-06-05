<?php

namespace App\Models;

use App\Enums\AutoCancelRefundMode;
use App\Enums\DeliveryFallbackAction;
use App\Enums\DeliveryFeeStrategy;
use App\Enums\DeliveryMode;
use App\Enums\RestaurantStatus;
use App\Enums\SelfDeliveryTipRecipient;
use App\Services\RestaurantImageService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Restaurant extends Model
{
    use HasFactory;

    /**
     * Mass-assignable columns. Sensitive Stripe fields
     * (stripe_account_id, application_fee_percent), file paths (logo_path),
     * and delivery-feature toggles are intentionally excluded — they're
     * assigned via direct property writes from trusted code paths only.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'subdomain',
        'custom_domain',
        'description',
        'email',
        'phone',
        'street',
        'street2',
        'city',
        'state',
        'postal_code',
        'country',
        'timezone',
        'primary_color',
        'secondary_color',
        'hero_tagline',
        'hero_cta_label',
        'hero_cta_url',
        'about_body',
        'social_links',
        'is_active',
        'status',
        'approved_at',
        'approved_by_user_id',
        'suspended_at',
        'suspension_reason',
        'onboarding_completed_at',
        'pending_custom_domain',
        'custom_domain_requested_at',
        'tax_rate_percent',
        'delivery_fee_cents',
        'delivery_enabled',
        'delivery_mode',
        'delivery_provider_priority',
        'delivery_fee_strategy',
        'customer_delivery_fee_cents_max',
        'self_delivery_tip_recipient',
        'delivery_fallback_action',
        'auto_cancel_refund_mode',
    ];

    /**
     * Apply the configured platform default fee rate when a restaurant is
     * created, unless an explicit rate was provided. Existing restaurants are
     * never touched here, so changing the platform default does not affect
     * already-created restaurants (grandfathering).
     */
    protected static function booted(): void
    {
        static::creating(function (Restaurant $restaurant): void {
            if (! isset($restaurant->attributes['application_fee_percent'])) {
                $restaurant->application_fee_percent = config('platform.default_application_fee_percent');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status' => RestaurantStatus::class,
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'custom_domain_requested_at' => 'datetime',
            'application_fee_percent' => 'decimal:2',
            'tax_rate_percent' => 'decimal:2',
            'delivery_fee_cents' => 'integer',
            'delivery_enabled' => 'boolean',
            'delivery_mode' => DeliveryMode::class,
            'delivery_provider_priority' => 'array',
            'delivery_fee_strategy' => DeliveryFeeStrategy::class,
            'customer_delivery_fee_cents_max' => 'integer',
            'self_delivery_tip_recipient' => SelfDeliveryTipRecipient::class,
            'delivery_fallback_action' => DeliveryFallbackAction::class,
            'auto_cancel_refund_mode' => AutoCancelRefundMode::class,
            'social_links' => 'array',
        ];
    }

    /**
     * Supported social-link platform keys. Other keys submitted to the
     * social endpoint are silently dropped.
     *
     * @var array<int, string>
     */
    public const SOCIAL_PLATFORMS = ['instagram', 'facebook', 'twitter', 'tiktok', 'youtube', 'website'];

    /**
     * Stripe Connect account status vocabulary stored in
     * `stripe_account_status`. `pending` = account created but onboarding not
     * finished; `enabled` = charges are live; `restricted` = Stripe disabled
     * charges/payouts (e.g. more documentation needed).
     */
    public const STRIPE_PENDING = 'pending';

    public const STRIPE_ENABLED = 'enabled';

    public const STRIPE_RESTRICTED = 'restricted';

    /**
     * True once the connected account can actually accept charges. This is the
     * gate for going live and for placing orders.
     */
    public function isStripeReady(): bool
    {
        return $this->stripe_account_status === self::STRIPE_ENABLED;
    }

    /**
     * Has a Connect account been created for this restaurant yet?
     */
    public function hasStripeAccount(): bool
    {
        return filled($this->stripe_account_id);
    }

    /**
     * Returns the configured social URLs keyed by platform. Empty/null
     * values are stripped. Platforms not present are absent from the map.
     *
     * @return array<string, string>
     */
    public function socialUrls(): array
    {
        $raw = is_array($this->social_links) ? $this->social_links : [];
        $out = [];
        foreach (self::SOCIAL_PLATFORMS as $platform) {
            $url = isset($raw[$platform]) ? trim((string) $raw[$platform]) : '';
            if ($url !== '') {
                $out[$platform] = $url;
            }
        }

        return $out;
    }

    /**
     * Restaurants that are eligible to appear on the public diner homepage:
     * fully active in the lifecycle AND not toggled offline by the owner.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query
            ->where('status', RestaurantStatus::Active)
            ->where('is_active', true);
    }

    public function isLive(): bool
    {
        return $this->status === RestaurantStatus::Active && (bool) $this->is_active;
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

    public function heroImageUrl(): ?string
    {
        return $this->variantUrl($this->hero_image_path, null);
    }

    public function heroImageMediumUrl(): ?string
    {
        return $this->variantUrl($this->hero_image_path, 'medium');
    }

    public function aboutImageUrl(): ?string
    {
        return $this->variantUrl($this->about_image_path, null);
    }

    public function aboutImageMediumUrl(): ?string
    {
        return $this->variantUrl($this->about_image_path, 'medium');
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

        return Storage::disk(RestaurantImageService::disk())->url($path);
    }

    /**
     * Page title for the storefront. Used in <title> and og:title.
     */
    public function seoTitle(): string
    {
        return $this->name;
    }

    /**
     * Up to ~160 chars of plain text describing the restaurant.
     * Picks the most specific available source: hero tagline → about body →
     * generic description. Used in <meta name="description"> and og:description.
     */
    public function seoDescription(): ?string
    {
        $candidates = [
            $this->hero_tagline,
            $this->about_body,
            $this->description,
        ];

        foreach ($candidates as $candidate) {
            $clean = trim((string) $candidate);
            if ($clean === '') {
                continue;
            }

            // Collapse whitespace and cap to 160 chars.
            $clean = preg_replace('/\s+/', ' ', $clean) ?? '';

            return mb_strlen($clean) > 160
                ? rtrim(mb_substr($clean, 0, 157)).'…'
                : $clean;
        }

        return null;
    }

    /**
     * Open Graph image URL: hero → first gallery photo → logo. Null if none.
     */
    public function ogImageUrl(): ?string
    {
        if ($url = $this->heroImageUrl()) {
            return $url;
        }

        $photo = $this->photos()->first();
        if ($photo && ($url = $photo->imageUrl())) {
            return $url;
        }

        return $this->logoUrl();
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

    /**
     * Users who have a customer relationship with this restaurant
     * (ordered from or signed up at it).
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'restaurant_customer')
            ->withPivot(['first_ordered_at', 'last_ordered_at', 'total_orders', 'total_spent_cents'])
            ->withTimestamps();
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

    public function photos(): HasMany
    {
        return $this->hasMany(RestaurantPhoto::class)
            ->orderBy('position')
            ->orderBy('id');
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
     * Returns the closing time of the currently-active hours window at
     * `$when`, or null when closed / always-open (no hours configured).
     */
    public function currentWindowClosesAt(?CarbonImmutable $when = null): ?CarbonImmutable
    {
        $tz = $this->timezone ?: 'America/New_York';
        $moment = ($when ?? CarbonImmutable::now())->setTimezone($tz);

        $all = $this->hours()->get();
        if ($all->isEmpty()) {
            return null;
        }

        $todayDow = (int) $moment->dayOfWeek;
        $yesterdayDow = ($todayDow + 6) % 7;
        $time = $moment->format('H:i:s');

        foreach ($all->where('day_of_week', $todayDow) as $h) {
            $opens = $h->opens_at;
            $closes = $h->closes_at;

            if ($closes > $opens && $time >= $opens && $time < $closes) {
                [$hh, $mm, $ss] = array_pad(explode(':', $closes), 3, '00');

                return $moment->setTime((int) $hh, (int) $mm, (int) $ss);
            }
            if ($closes <= $opens && $time >= $opens) {
                // Window crosses midnight; closes_at refers to next day.
                [$hh, $mm, $ss] = array_pad(explode(':', $closes), 3, '00');

                return $moment->addDay()->setTime((int) $hh, (int) $mm, (int) $ss);
            }
        }

        foreach ($all->where('day_of_week', $yesterdayDow) as $h) {
            $opens = $h->opens_at;
            $closes = $h->closes_at;

            if ($closes <= $opens && $time < $closes) {
                [$hh, $mm, $ss] = array_pad(explode(':', $closes), 3, '00');

                return $moment->setTime((int) $hh, (int) $mm, (int) $ss);
            }
        }

        return null;
    }

    /**
     * Single human-friendly status label combining open + next-open logic.
     * Examples: "Open until 9 PM", "Opens at 11 AM today",
     * "Opens tomorrow at 11 AM", "Opens Monday at 11 AM". Null when no
     * hours are configured (always-open fallback).
     */
    public function formatOpenStatus(?CarbonImmutable $when = null): ?string
    {
        $tz = $this->timezone ?: 'America/New_York';
        $moment = ($when ?? CarbonImmutable::now())->setTimezone($tz);

        if (! $this->hours()->exists()) {
            return null;
        }

        if ($this->isOpenAt($moment)) {
            $closes = $this->currentWindowClosesAt($moment);
            if (! $closes) {
                return 'Open now';
            }

            return 'Open until '.$closes->format('g:i A');
        }

        return $this->formatNextOpenAt($moment);
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
