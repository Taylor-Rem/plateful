<?php

namespace App\Models;

use App\Support\Menus\IngredientGroupCompiler;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ingredient of one menu item, with the plain-language rules the owner
 * set: can it be left out, can customers add extra (and for how much), can
 * it be swapped for something from a swap set (a single-group template).
 * {@see IngredientGroupCompiler} turns these rows into
 * the item-owned option groups the configurator and cart run on.
 */
class MenuItemIngredient extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_removable' => 'boolean',
            'extra_price_cents' => 'integer',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function swapTemplate(): BelongsTo
    {
        return $this->belongsTo(ItemTemplate::class, 'swap_template_id');
    }

    public function generatedOptions(): HasMany
    {
        return $this->hasMany(ItemTemplateOption::class);
    }

    public function offersExtra(): bool
    {
        return $this->extra_price_cents !== null;
    }

    public function isSwappable(): bool
    {
        return $this->swap_template_id !== null;
    }
}
