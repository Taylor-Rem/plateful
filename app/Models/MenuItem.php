<?php

namespace App\Models;

use App\Services\RestaurantImageService;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MenuItem extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'price_cents' => 'integer',
            'position' => 'integer',
        ];
    }

    public function imageUrl(): ?string
    {
        return $this->variantUrl(null);
    }

    public function imageMediumUrl(): ?string
    {
        return $this->variantUrl('medium');
    }

    public function imageThumbUrl(): ?string
    {
        return $this->variantUrl('thumb');
    }

    protected function variantUrl(?string $variant): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        $path = $this->image_path;

        if ($variant !== null) {
            $dir = trim((string) Str::beforeLast($path, '/'), '/');
            $name = Str::beforeLast(Str::afterLast($path, '/'), '.');
            $prefix = $dir === '' ? '' : $dir.'/';
            $path = "{$prefix}{$name}-{$variant}.webp";
        }

        return Storage::disk(RestaurantImageService::DISK)->url($path);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(MenuItemModifier::class);
    }
}
