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

    /** Generated from an ingredient: the ingredient itself in the "included" group. */
    public const KIND_INCLUDED = 'included';

    /** Generated from an ingredient: "Extra {ingredient}". */
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
