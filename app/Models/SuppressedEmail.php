<?php

namespace App\Models;

use App\Enums\EmailSuppressionReason;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform-wide do-not-mail address (hard bounce, complaint, or manual
 * block). Suppression is global on purpose: a bounce at one restaurant proves
 * the inbox is bad for every restaurant.
 */
class SuppressedEmail extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reason' => EmailSuppressionReason::class,
            'created_at' => 'datetime',
        ];
    }
}
