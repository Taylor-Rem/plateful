<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Mail\CampaignReviewSubmittedMail;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignContentReviewer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Automated review of a held (pending_review) campaign. Approve → dispatch
 * the send, so a clean campaign clears review in about a minute. Flag, no
 * verdict, or repeated API failure → the campaign stays pending_review and
 * the platform is pinged for a human look (fail closed). Holds the campaign
 * id, not the model — queue workers run without a bound tenant.
 */
class ReviewCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $campaignId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(CampaignContentReviewer $reviewer): void
    {
        $campaign = Campaign::withoutTenantScope()
            ->with('restaurant')
            ->find($this->campaignId);

        // Withdrawn (or already handled by a human) while queued.
        if (! $campaign || $campaign->status !== CampaignStatus::PendingReview) {
            return;
        }

        $verdict = $reviewer->review($campaign);

        if ($verdict === null) {
            $this->escalate($campaign, 'Automated review was unavailable — needs a human look.');

            return;
        }

        $campaign->forceFill([
            'review_verdict' => $verdict['approved'] ? 'approved' : 'flagged',
            'review_notes' => $verdict['reason'],
            'reviewed_at' => now(),
        ])->save();

        if (! $verdict['approved']) {
            $this->escalate($campaign);

            return;
        }

        $sendAt = $campaign->scheduled_at;

        if ($sendAt !== null && $sendAt->isFuture()) {
            $campaign->forceFill(['status' => CampaignStatus::Scheduled])->save();

            SendCampaign::dispatch($campaign->id, $campaign->scheduled_at->toIso8601String())
                ->delay($sendAt);

            return;
        }

        $campaign->forceFill(['status' => CampaignStatus::Scheduled, 'scheduled_at' => null])->save();

        SendCampaign::dispatch($campaign->id);
    }

    /**
     * The API failed repeatedly — leave the campaign held and ping a human.
     */
    public function failed(?Throwable $exception): void
    {
        $campaign = Campaign::withoutTenantScope()
            ->with('restaurant')
            ->find($this->campaignId);

        if (! $campaign || $campaign->status !== CampaignStatus::PendingReview) {
            return;
        }

        Log::error('Automated campaign review failed, escalating to human review', [
            'campaign_id' => $this->campaignId,
            'error' => $exception?->getMessage(),
        ]);

        $this->escalate($campaign, 'Automated review errored — needs a human look.');
    }

    protected function escalate(Campaign $campaign, ?string $notes = null): void
    {
        if ($notes !== null) {
            $campaign->forceFill(['review_notes' => $notes, 'reviewed_at' => now()])->save();
        }

        Mail::to(config('mail.senders.support'))->queue(new CampaignReviewSubmittedMail($campaign));
    }
}
