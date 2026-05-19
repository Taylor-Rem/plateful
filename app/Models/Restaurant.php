<?php

namespace App\Models;

use App\Services\RestaurantImageService;
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

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'restaurant_user')->withTimestamps();
    }
}
