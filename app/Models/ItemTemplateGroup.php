<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemTemplateGroup extends Model
{
    use HasFactory;

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
