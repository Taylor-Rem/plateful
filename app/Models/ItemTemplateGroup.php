<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemTemplateGroup extends Model
{
    use HasFactory;

    /** A decision the owner built by hand or the import produced (size, choice of side). */
    public const KIND_CHOICE = 'choice';

    /** Generated: the item's removable ingredients, all default-on; unchecking leaves one out. */
    public const KIND_INCLUDED = 'included';

    /** Generated: "Extra {ingredient}" add-ons with their prices. */
    public const KIND_EXTRAS = 'extras';

    /** A swap set attached through an ingredient (the group itself lives on the reusable template). */
    public const KIND_SWAP = 'swap';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'position' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ItemTemplate::class, 'item_template_id');
    }

    /**
     * Item-owned groups have no template; they were compiled from the item's
     * ingredients and live only on that item.
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function isItemOwned(): bool
    {
        return $this->menu_item_id !== null;
    }

    public function options(): HasMany
    {
        return $this->hasMany(ItemTemplateOption::class)->orderBy('position');
    }

    public function isSingleSelect(): bool
    {
        return $this->max_selections === 1;
    }

    public function isRequired(): bool
    {
        return ($this->min_selections ?? 0) > 0;
    }
}
