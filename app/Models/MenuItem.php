<?php

namespace App\Models;

use App\Services\RestaurantImageService;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
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

    /**
     * Reusable templates attached to this item, in display order. An item
     * may hold several ("Sandwich size" plus a "Cheeses" swap set).
     */
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(ItemTemplate::class, 'menu_item_templates')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * Groups compiled from this item's ingredients ("included", "extras").
     * They belong to this item only.
     */
    public function ownGroups(): HasMany
    {
        return $this->hasMany(ItemTemplateGroup::class)->orderBy('position');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(MenuItemIngredient::class)->orderBy('position');
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
     * Every option group a customer can configure on this item, in the order
     * the configurator shows them: attached templates' groups first, then the
     * item's own compiled groups. Loads what isn't already loaded.
     *
     * @return Collection<int, ItemTemplateGroup>
     */
    public function optionGroups(): Collection
    {
        $templates = $this->relationLoaded('templates')
            ? $this->templates
            : $this->templates()->with('groups.options')->get();

        $own = $this->relationLoaded('ownGroups')
            ? $this->ownGroups
            : $this->ownGroups()->with('options')->get();

        $groups = collect();
        foreach ($templates as $template) {
            $templateGroups = $template->relationLoaded('groups')
                ? $template->groups
                : $template->groups()->with('options')->get();
            foreach ($templateGroups as $group) {
                $groups->push($group);
            }
        }
        foreach ($own as $group) {
            $groups->push($group);
        }

        return $groups->values();
    }

    public function isConfigurable(): bool
    {
        return $this->optionGroups()->isNotEmpty();
    }

    /**
     * Compute the unit price for a set of selected option ids.
     * Pricing model: base price reflects the default configuration.
     * Price = base + sum(deltas for chosen options NOT in defaults)
     *              - sum(deltas for defaults NOT currently chosen).
     *
     * @param  array<int, int>  $optionIds
     */
    public function priceForSelectionsCents(array $optionIds): int
    {
        $groups = $this->optionGroups();

        if ($groups->isEmpty()) {
            return $this->price_cents;
        }

        $validOptions = collect();
        foreach ($groups as $group) {
            foreach ($group->options as $opt) {
                $validOptions->put($opt->id, $opt);
            }
        }

        $selected = collect($optionIds)->unique();
        $unknown = $selected->reject(fn ($id) => $validOptions->has($id));
        if ($unknown->isNotEmpty()) {
            throw new InvalidArgumentException(
                'Option ids do not belong to this item: '.$unknown->implode(', ')
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
