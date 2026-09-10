<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemTemplate extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(ItemTemplateGroup::class)->orderBy('position');
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_templates')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * A swap set is a template with exactly one single-select group — the
     * shape an ingredient can point at ("swap with: Cheeses").
     */
    public function isSwapSet(): bool
    {
        $groups = $this->relationLoaded('groups') ? $this->groups : $this->groups()->get();

        return $groups->count() === 1 && $groups->first()->isSingleSelect();
    }
}
