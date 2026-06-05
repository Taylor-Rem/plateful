<?php

namespace App\Models;

use App\Services\RestaurantImageService;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MenuItem extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Mass-assignable columns. Excludes `id`, timestamps, and `image_path`
     * (managed via RestaurantImageService).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'restaurant_id',
        'menu_category_id',
        'item_template_id',
        'name',
        'slug',
        'description',
        'price_cents',
        'is_available',
        'is_featured',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
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

        return Storage::disk(RestaurantImageService::disk())->url($path);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ItemTemplate::class, 'item_template_id');
    }

    public function defaultSelections(): BelongsToMany
    {
        return $this->belongsToMany(
            ItemTemplateOption::class,
            'menu_item_default_selections',
            'menu_item_id',
            'item_template_option_id',
        )->withTimestamps();
    }

    /**
     * Compute the unit price for a set of selected template option ids.
     * Pricing model: base price reflects the default configuration.
     * Price = base + sum(deltas for chosen options NOT in defaults)
     *              - sum(deltas for defaults NOT currently chosen).
     *
     * @param  array<int, int>  $optionIds
     */
    public function priceForSelectionsCents(array $optionIds): int
    {
        if ($this->item_template_id === null) {
            return $this->price_cents;
        }

        $template = $this->relationLoaded('template')
            ? $this->template
            : $this->template()->with('groups.options')->first();

        if (! $template) {
            return $this->price_cents;
        }

        $validOptions = collect();
        foreach ($template->groups as $group) {
            foreach ($group->options as $opt) {
                $validOptions->put($opt->id, $opt);
            }
        }

        $selected = collect($optionIds)->unique();
        $unknown = $selected->reject(fn ($id) => $validOptions->has($id));
        if ($unknown->isNotEmpty()) {
            throw new InvalidArgumentException(
                'Option ids do not belong to this item template: '.$unknown->implode(', ')
            );
        }

        $defaultIds = $this->relationLoaded('defaultSelections')
            ? $this->defaultSelections->pluck('id')
            : $this->defaultSelections()->pluck('item_template_options.id');

        $defaultIds = $defaultIds->map(fn ($id) => (int) $id);
        $selectedSet = $selected->map(fn ($id) => (int) $id);

        $addedCents = $selectedSet
            ->reject(fn ($id) => $defaultIds->contains($id))
            ->sum(fn ($id) => $validOptions[$id]->price_delta_cents);

        $removedCents = $defaultIds
            ->reject(fn ($id) => $selectedSet->contains($id))
            ->sum(fn ($id) => $validOptions->has($id) ? $validOptions[$id]->price_delta_cents : 0);

        return (int) ($this->price_cents + $addedCents - $removedCents);
    }
}
