<?php

namespace App\Models;

use App\Services\RestaurantImageService;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RestaurantPhoto extends Model
{
    use BelongsToTenant;

    /**
     * Mass-assignable columns. Excludes `image_path` (managed via
     * RestaurantImageService).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'restaurant_id',
        'caption',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
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
}
