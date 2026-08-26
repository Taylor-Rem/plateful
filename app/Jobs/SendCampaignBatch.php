<?php

namespace App\Jobs;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SuppressedEmail;
use App\Services\Campaigns\CampaignMailer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Deliver one ≤100-recipient slice of a campaign through the Resend batch
 * endpoint. Idempotent on retry: only rows still `queued` are sent (a row is
 * marked `sent` the moment its message id lands), and the Resend call carries
 * an idempotency key derived from the slice, so a retried job never
 * double-sends.
 */
class SendCampaignBatch implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    /**
     * @param  array<int, int>  $recipientIds
     */
    public function __construct(public int $campaignId, public array $recipientIds) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Resend allows 2 requests/second by default. The limiter only matters
     * when a real key is configured — keyless (local/test) runs skip it.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return app(CampaignMailer::class)->isConfigured()
            ? [new RateLimited('campaign-batches')]
            : [];
    }

    public function handle(CampaignMailer $mailer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $campaign = Campaign::withoutTenantScope()
            ->with('restaurant')
            ->find($this->campaignId);

        // Auto-pause / cancellation mid-send: remaining batches abort here.
        if (! $campaign || $campaign->status !== CampaignStatus::Sending) {
            return;
        }

        $recipients = CampaignRecipient::query()
            ->whereIn('id', $this->recipientIds)
            ->where('campaign_id', $this->campaignId)
            ->where('status', CampaignRecipientStatus::Queued)
            ->with('user')
            ->get();

        // Suppression can land between snapshot and send (e.g. a bounce from
        // an earlier batch); a vanished user means no unsubscribe URL, so the
        // row cannot be compliantly mailed either way.
        $suppressed = SuppressedEmail::query()
            ->whereIn('email', $recipients->pluck('email'))
            ->pluck('email');

        [$blocked, $sendable] = $recipients->partition(
            fn (CampaignRecipient $recipient): bool => $recipient->user === null || $suppressed->contains($recipient->email),
        );

        if ($blocked->isNotEmpty()) {
            CampaignRecipient::query()
                ->whereIn('id', $blocked->pluck('id'))
                ->update(['status' => CampaignRecipientStatus::Failed]);
        }

        if ($sendable->isEmpty()) {
            return;
        }

        $messageIds = $mailer->send($campaign, $sendable, $this->idempotencyKey());

        foreach ($sendable as $recipient) {
            $recipient->forceFill([
                'status' => CampaignRecipientStatus::Sent,
                'resend_message_id' => $messageIds[$recipient->id] ?? null,
                'sent_at' => now(),
            ])->save();
        }
    }

    /**
     * Stable across retries of the same slice, so Resend deduplicates a call
     * that succeeded but whose row-marking was interrupted.
     */
    protected function idempotencyKey(): string
    {
        return "campaign-{$this->campaignId}-".md5(implode(',', $this->recipientIds));
    }
}
