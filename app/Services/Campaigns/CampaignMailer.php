<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Restaurant;
use App\Services\MarketingConsentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Resend;

/**
 * Delivers campaign email through Resend's batch endpoint (≤100 messages per
 * request). Container-bound singleton: production carries the API key; local
 * dev and tests run keyless, where batches are logged instead of sent and
 * deterministic fake ids come back — the whole pipeline stays exercisable
 * without a network.
 */
class CampaignMailer
{
    /**
     * Resend's batch endpoint ceiling per request.
     */
    public const MAX_BATCH_SIZE = 100;

    public function __construct(
        protected MarketingConsentService $consent,
        protected ?string $apiKey = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Send one batch of at most MAX_BATCH_SIZE recipients (user eager-loaded).
     * Returns the provider message id for each recipient row id.
     *
     * @param  Collection<int, CampaignRecipient>  $recipients
     * @return array<int, string|null>
     */
    public function send(Campaign $campaign, Collection $recipients, ?string $idempotencyKey = null): array
    {
        $recipients = $recipients->values();

        $messages = $recipients
            ->map(fn (CampaignRecipient $recipient): array => $this->buildMessage($campaign, $recipient))
            ->all();

        if (! $this->isConfigured()) {
            Log::info('CampaignMailer: no Resend key configured, logging batch instead of sending', [
                'campaign_id' => $campaign->id,
                'subject' => $campaign->subject,
                'to' => $recipients->pluck('email')->all(),
            ]);

            return $recipients
                ->mapWithKeys(fn (CampaignRecipient $recipient) => [$recipient->id => 'log-'.Str::uuid()])
                ->all();
        }

        $response = Resend::client((string) $this->apiKey)->batch->send(
            $messages,
            $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : [],
        );

        $messageIds = array_map(fn ($email) => $email->id ?? null, $response->data ?? []);

        return $recipients
            ->mapWithKeys(fn (CampaignRecipient $recipient, int $i) => [$recipient->id => $messageIds[$i] ?? null])
            ->all();
    }

    /**
     * The Resend payload for one recipient: shared-domain sender identity,
     * reply-to the restaurant's real inbox, RFC 8058 one-click unsubscribe
     * headers, and the rendered structured template.
     *
     * @return array<string, mixed>
     */
    public function buildMessage(Campaign $campaign, CampaignRecipient $recipient): array
    {
        $restaurant = $campaign->restaurant;
        $unsubscribeUrl = $this->consent->unsubscribeUrl($recipient->user, $restaurant);

        return [
            'from' => $this->fromAddress($restaurant),
            'to' => [$recipient->email],
            'reply_to' => $restaurant->email,
            'subject' => $campaign->subject,
            'html' => view('emails.campaign', [
                'campaign' => $campaign,
                'restaurant' => $restaurant,
                'unsubscribeUrl' => $unsubscribeUrl,
            ])->render(),
            'headers' => [
                'List-Unsubscribe' => '<'.$unsubscribeUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ];
    }

    /**
     * Shared marketing-domain identity: "{Restaurant Name} <{subdomain}@{domain}>".
     * Session 4 teaches this to prefer a restaurant's own verified domain.
     */
    public function fromAddress(Restaurant $restaurant): string
    {
        $domain = (string) config('mail.marketing_domain');

        return "{$restaurant->name} <{$restaurant->subdomain}@{$domain}>";
    }
}
