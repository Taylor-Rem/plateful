<?php

namespace App\Models;

use Database\Factories\ApiCallLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One MCP tool call or operator REST request, as seen by the restaurant it
 * touched: who (key or user), what (tool or route name), with which
 * arguments (redacted), whether it succeeded, and how long it took. Written
 * by App\Support\Api\ApiCallLogger; never updated.
 */
class ApiCallLog extends Model
{
    /** @use HasFactory<ApiCallLogFactory> */
    use HasFactory;

    public const CHANNEL_MCP = 'mcp';

    public const CHANNEL_REST = 'rest';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'ok' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
