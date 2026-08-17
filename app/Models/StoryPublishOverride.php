<?php

namespace App\Models;

use Database\Factories\StoryPublishOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Overrides the `published` front matter of a flat-file story (matched by
 * slug) so publishing is decoupled from deploying. Content stays in git;
 * only the visibility flag lives here. A row that agrees with the file's
 * front matter is redundant and gets cleaned up by the super-admin page.
 */
class StoryPublishOverride extends Model
{
    /** @use HasFactory<StoryPublishOverrideFactory> */
    use HasFactory;

    protected $fillable = ['slug', 'published'];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }
}
