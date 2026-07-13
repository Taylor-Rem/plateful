<?php

namespace App\Models;

use App\Enums\MenuImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'status',
        'file_paths',
        'result',
        'error',
        'model',
        'input_tokens',
        'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'status' => MenuImportStatus::class,
            'file_paths' => 'array',
            'result' => 'array',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Total items in the extracted draft (0 until extraction finishes).
     */
    public function itemCount(): int
    {
        return collect($this->result['categories'] ?? [])
            ->sum(fn (array $category): int => count($category['items'] ?? []));
    }
}
