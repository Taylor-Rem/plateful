<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CampaignStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case PausedByPlatform = 'paused_by_platform';
}
