<?php

namespace App\Services\Campaigns;

use Anthropic\Client;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;
use App\Models\Campaign;

/**
 * Automated content review of a held campaign (replaces the human-only
 * first-campaign queue). One schema-constrained Claude call returns an
 * approve/flag verdict with reasoning for the super-admin console. Returns
 * null whenever a trustworthy verdict cannot be produced — no API key,
 * refusal, truncation, unusable response — so callers fail closed to a
 * human. Tests swap this class out at the container level, like
 * MenuExtractionService.
 */
class CampaignContentReviewer
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You review marketing emails that restaurant owners compose on Plateful, a multi-tenant online-ordering platform. Each email goes only to customers of that one restaurant who explicitly opted in, and the platform appends its own compliance footer (physical address + unsubscribe link) — you are not checking compliance mechanics, only the owner-authored content.

        Approve unless the content clearly falls into one of these:
        - Deceptive or fraudulent: fake urgency about nonexistent offers, bait pricing, misrepresenting who the sender is, impersonating another business or brand.
        - Illegal goods or services, or promotions unlawful for a restaurant to run (e.g. raffles/gambling framed as required purchase).
        - Adult, hateful, harassing, or violent content.
        - Malicious or mismatched links: a call-to-action URL that is suspicious, unrelated to the restaurant, or designed to phish.
        - Content that is not restaurant marketing at all: political campaigning, unrelated products, chain letters, solicitation of personal or financial data.
        - Placeholder or gibberish content that was plainly not meant to be sent ("test test", lorem ipsum) — flag so a human can confirm before it reaches real customers.

        NOT grounds for flagging: typos, informal or exuberant tone, aggressive discounts, mediocre copy, short messages, missing images, or a plain storefront link. Restaurants promoting alcohol with food is normal and acceptable. When genuinely uncertain, flag rather than approve — a human reviews every flag.

        Respond with a verdict and a reason of one or two sentences. The reason is shown to the platform's human reviewer; for approvals a brief note is fine, for flags say specifically what to look at.
        PROMPT;

    public function __construct(protected ?string $apiKey = null) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * @return array{approved: bool, reason: string}|null null = no trustworthy verdict; caller escalates to a human
     */
    public function review(Campaign $campaign): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $client = new Client(apiKey: (string) $this->apiKey);

        $message = $client->messages->create(
            model: (string) config('platform.campaigns.review.model'),
            maxTokens: 8000,
            system: self::SYSTEM_PROMPT,
            messages: [['role' => 'user', 'content' => $this->campaignSummary($campaign)]],
            outputConfig: OutputConfig::with(format: JSONOutputFormat::with(schema: self::schema())),
        );

        // A refusal or truncation is itself a reason for a human to look —
        // treat both as "no verdict" rather than guessing.
        if ($message->stopReason !== 'end_turn') {
            return null;
        }

        $text = collect($message->content)
            ->firstWhere('type', 'text')
            ?->text;

        $decoded = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($decoded) || ! is_bool($decoded['approved'] ?? null)) {
            return null;
        }

        return [
            'approved' => $decoded['approved'],
            'reason' => (string) ($decoded['reason'] ?? ''),
        ];
    }

    protected function campaignSummary(Campaign $campaign): string
    {
        $restaurant = $campaign->restaurant;
        $filter = $campaign->audience_filter ?? ['type' => 'all'];

        return json_encode([
            'restaurant' => [
                'name' => $restaurant->name,
                'storefront_url' => $restaurant->publicUrl(),
                'description' => $restaurant->description,
            ],
            'campaign' => [
                'subject' => $campaign->subject,
                'preheader' => $campaign->preheader,
                'headline' => $campaign->headline,
                'body' => $campaign->body,
                'offer_callout' => $campaign->offer_callout,
                'cta_label' => $campaign->cta_label ?? 'Order now (default)',
                'cta_url' => $campaign->cta_url ?? $restaurant->publicUrl().' (default storefront link)',
                'audience' => $filter['type'] ?? 'all',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'approved' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['approved', 'reason'],
            'additionalProperties' => false,
        ];
    }
}
