<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CampaignRecipientStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Unsubscribed = 'unsubscribed';
}
