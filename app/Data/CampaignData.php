<?php

namespace App\Data;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CampaignData extends Data
{
    /**
     * @param  array{type: string, days?: int, min_orders?: int}  $audienceFilter
     */
    public function __construct(
        public int $id,
        public string $subject,
        public ?string $preheader,
        public string $headline,
        public string $body,
        public ?string $offerCallout,
        public ?string $ctaLabel,
        public ?string $ctaUrl,
        public array $audienceFilter,
        public string $audienceLabel,
        public CampaignStatus $status,
        public ?string $scheduledAt,
        public ?string $sentAt,
        public int $recipientsCount,
        public int $deliveredCount,
        public int $bouncedCount,
        public int $complainedCount,
        public int $unsubscribedCount,
        public ?string $createdAt,
    ) {}

    public static function fromModel(Campaign $campaign): self
    {
        $filter = $campaign->audience_filter ?? ['type' => 'all'];

        return new self(
            id: $campaign->id,
            subject: $campaign->subject,
            preheader: $campaign->preheader,
            headline: $campaign->headline,
            body: $campaign->body,
            offerCallout: $campaign->offer_callout,
            ctaLabel: $campaign->cta_label,
            ctaUrl: $campaign->cta_url,
            audienceFilter: $filter,
            audienceLabel: self::audienceLabel($filter),
            status: $campaign->status,
            scheduledAt: $campaign->scheduled_at?->toIso8601String(),
            sentAt: $campaign->sent_at?->toIso8601String(),
            recipientsCount: (int) $campaign->recipients_count,
            deliveredCount: (int) $campaign->delivered_count,
            bouncedCount: (int) $campaign->bounced_count,
            complainedCount: (int) $campaign->complained_count,
            unsubscribedCount: (int) $campaign->unsubscribed_count,
            createdAt: $campaign->created_at?->toIso8601String(),
        );
    }

    /**
     * @param  array{type?: string, days?: int, min_orders?: int}  $filter
     */
    protected static function audienceLabel(array $filter): string
    {
        return match ($filter['type'] ?? 'all') {
            'lapsed' => 'Lapsed customers (no order in '.($filter['days'] ?? 30).'+ days)',
            'regulars' => 'Regulars ('.($filter['min_orders'] ?? 3).'+ orders)',
            default => 'All opted-in customers',
        };
    }
}
