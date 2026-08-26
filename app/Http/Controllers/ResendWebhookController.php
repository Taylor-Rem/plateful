<?php

namespace App\Http\Controllers;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailSuppressionReason;
use App\Enums\MarketingConsentSource;
use App\Mail\CampaignAutoPausedMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SuppressedEmail;
use App\Services\MarketingConsentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Resend\Exceptions\WebhookSignatureVerificationException;
use Resend\WebhookSignature;

/**
 * Resend event webhooks for campaign email (campaigns plan, Session 3):
 * delivered increments counters and earns the restaurant its campaigns
 * approval; a hard bounce suppresses the address platform-wide; a complaint
 * suppresses, auto-opts the customer out at that restaurant, and can trip the
 * auto-pause. Events are matched to recipients by resend_message_id, so
 * transactional mail on the same Resend account is ignored harmlessly.
 */
class ResendWebhookController extends Controller
{
    public function __construct(private MarketingConsentService $consent) {}

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.resend.webhook_secret');

        if ($secret === '') {
            Log::error('Resend webhook received but RESEND_WEBHOOK_SECRET is not configured.');

            return response('Webhook not configured.', 400);
        }

        try {
            WebhookSignature::verify(
                $request->getContent(),
                [
                    'svix-id' => (string) $request->header('svix-id', ''),
                    'svix-timestamp' => (string) $request->header('svix-timestamp', ''),
                    'svix-signature' => (string) $request->header('svix-signature', ''),
                ],
                $secret,
            );
        } catch (WebhookSignatureVerificationException) {
            return response('Invalid signature.', 400);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response('Invalid payload.', 400);
        }

        $type = (string) ($payload['type'] ?? '');
        $emailId = $payload['data']['email_id'] ?? null;

        if (! is_string($emailId) || $emailId === '') {
            return response('OK');
        }

        $recipient = CampaignRecipient::query()
            ->where('resend_message_id', $emailId)
            ->first();

        // Test sends and transactional mail share the Resend account but
        // never have a recipient row — acknowledge so Svix stops retrying.
        if (! $recipient) {
            return response('OK');
        }

        $campaign = Campaign::withoutTenantScope()
            ->with('restaurant')
            ->find($recipient->campaign_id);

        if (! $campaign || ! $campaign->restaurant) {
            return response('OK');
        }

        match ($type) {
            'email.delivered' => $this->handleDelivered($campaign, $recipient),
            'email.bounced' => $this->handleBounced($campaign, $recipient, $payload),
            'email.complained' => $this->handleComplained($campaign, $recipient),
            default => null,
        };

        return response('OK');
    }

    protected function handleDelivered(Campaign $campaign, CampaignRecipient $recipient): void
    {
        // The null-check makes Svix redeliveries idempotent on the counter.
        if ($recipient->delivered_at !== null) {
            return;
        }

        $recipient->forceFill(['delivered_at' => now()])->save();
        $campaign->increment('delivered_count');

        // One clean delivery is the "clean send" proof that graduates the
        // restaurant out of the first-campaign review queue.
        $restaurant = $campaign->restaurant;

        if ($restaurant->needsFirstCampaignReview() && ! $restaurant->campaignsPaused()) {
            $restaurant->forceFill(['campaigns_approved_at' => now()])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleBounced(Campaign $campaign, CampaignRecipient $recipient, array $payload): void
    {
        if ($recipient->status !== CampaignRecipientStatus::Bounced) {
            $recipient->forceFill(['status' => CampaignRecipientStatus::Bounced])->save();
            $campaign->increment('bounced_count');
        }

        // Resend relays the SES bounce classification; only a transient
        // (soft) bounce leaves the address mailable. Missing classification
        // is treated as hard — the conservative default for marketing mail.
        $bounceType = strtolower((string) ($payload['data']['bounce']['type'] ?? 'permanent'));

        if ($bounceType !== 'transient') {
            SuppressedEmail::query()->firstOrCreate(
                ['email' => $recipient->email],
                ['reason' => EmailSuppressionReason::HardBounce],
            );
        }
    }

    protected function handleComplained(Campaign $campaign, CampaignRecipient $recipient): void
    {
        // Status transition doubles as the idempotency guard for counters,
        // suppression, and the pause check.
        if ($recipient->status === CampaignRecipientStatus::Complained) {
            return;
        }

        $recipient->forceFill(['status' => CampaignRecipientStatus::Complained])->save();
        $campaign->increment('complained_count');

        SuppressedEmail::query()->firstOrCreate(
            ['email' => $recipient->email],
            ['reason' => EmailSuppressionReason::Complaint],
        );

        if ($recipient->user) {
            $this->consent->optOutEmail($recipient->user, $campaign->restaurant, MarketingConsentSource::Admin);
        }

        $this->maybeAutoPause($campaign->refresh()->load('restaurant'));
    }

    /**
     * Complaint auto-pause: both an absolute floor (>= min complaints) and a
     * rate above the configured fraction of recipients. Halts the campaign
     * mid-send (remaining batches abort on the status guard) and pauses the
     * restaurant's sending pending super-admin review.
     */
    protected function maybeAutoPause(Campaign $campaign): void
    {
        if ($campaign->status === CampaignStatus::PausedByPlatform) {
            return;
        }

        $min = (int) config('platform.campaigns.complaint_pause_min');
        $rate = (float) config('platform.campaigns.complaint_pause_rate');

        $overFloor = $campaign->complained_count >= $min;
        $overRate = $campaign->complained_count > max(1, $campaign->recipients_count) * $rate;

        if (! $overFloor || ! $overRate) {
            return;
        }

        $campaign->forceFill(['status' => CampaignStatus::PausedByPlatform])->save();
        $campaign->restaurant->forceFill(['campaigns_paused_at' => now()])->save();

        Log::warning('Campaign auto-paused on complaints', [
            'campaign_id' => $campaign->id,
            'restaurant_id' => $campaign->restaurant_id,
            'complained_count' => $campaign->complained_count,
            'recipients_count' => $campaign->recipients_count,
        ]);

        Mail::to(config('mail.senders.support'))->queue(new CampaignAutoPausedMail($campaign));
    }
}
