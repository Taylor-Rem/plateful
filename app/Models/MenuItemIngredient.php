<?php

namespace App\Models;

use App\Support\Menus\IngredientGroupCompiler;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ingredient of one menu item, with the plain-language rules the owner
 * set: can it be left out (None), can customers take half, can they double
 * it (and for how much), can it be swapped for something from a swap set
 * (a single-group template). Regular is always offered.
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
            'allow_half' => 'boolean',
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

    /**
     * The levels this ingredient offers, in display order. Regular is always
     * there; the rest follow the owner's rules. A lone Regular means the
     * ingredient is not customizable and gets no row.
     *
     * @return array<int, string>
     */
    public function levels(): array
    {
        $levels = [];
        if ($this->is_removable) {
            $levels[] = ItemTemplateOption::LEVEL_NONE;
        }
        if ($this->allow_half) {
            $levels[] = ItemTemplateOption::LEVEL_HALF;
        }
        $levels[] = ItemTemplateOption::LEVEL_REGULAR;
        if ($this->offersExtra()) {
            $levels[] = ItemTemplateOption::LEVEL_DOUBLE;
        }

        return $levels;
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
