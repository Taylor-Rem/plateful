<?php

namespace App\Enums;

enum MenuImportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case NeedsReview = 'needs_review';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Still occupying the pipeline: a new import cannot start while one of
     * these exists for the restaurant.
     */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::Queued, self::Processing, self::NeedsReview => true,
            self::Completed, self::Failed => false,
        };
    }
}
