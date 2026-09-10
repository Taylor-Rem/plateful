<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ItemTemplateOption extends Model
{
    use HasFactory;

    public const KIND_CHOICE = 'choice';

    /** Generated from an ingredient: one of its levels (None / Half / Regular / Double). */
    public const KIND_LEVEL = 'level';

    public const LEVEL_NONE = 'None';

    public const LEVEL_HALF = 'Half';

    public const LEVEL_REGULAR = 'Regular';

    public const LEVEL_DOUBLE = 'Double';

    /** Legacy kinds (before 2026-09-10 levels): kept so old snapshots still render. */
    public const KIND_INCLUDED = 'included';

    public const KIND_EXTRA = 'extra';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'is_available' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemTemplateGroup::class, 'item_template_group_id');
    }

    /**
     * Set on generated options so the compiler can upsert them by
     * (ingredient, kind) and keep their ids stable across saves.
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(MenuItemIngredient::class, 'menu_item_ingredient_id');
    }

    public function menuItemsAsDefault(): BelongsToMany
    {
        return $this->belongsToMany(
            MenuItem::class,
            'menu_item_default_selections',
            'item_template_option_id',
            'menu_item_id',
        )->withTimestamps();
    }
}
