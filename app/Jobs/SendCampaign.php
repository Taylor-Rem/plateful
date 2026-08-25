<?php

namespace App\Jobs;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\RestaurantCustomer;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignMailer;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Take a scheduled campaign and fan it out: resolve the audience AT EXECUTION
 * TIME (an opt-out after scheduling is honored), snapshot recipients, then
 * chunk into SendCampaignBatch jobs via Bus::batch. Holds the campaign id, not
 * the model — queue workers run without a bound tenant.
 *
 * Scheduled sends are delayed dispatches of this job; the status guard below
 * is what makes cancel-while-scheduled work without a scheduler.
 */
class SendCampaign implements ShouldQueue
{
    use Queueable;

    /**
     * $expectedScheduledAt is stamped on delayed (scheduled) dispatches: the
     * job only fires if the campaign is still scheduled for that exact moment,
     * so a reschedule's stale earlier dispatch aborts instead of sending early.
     */
    public function __construct(public int $campaignId, public ?string $expectedScheduledAt = null) {}

    public function handle(CampaignAudience $audience): void
    {
        $campaign = Campaign::withoutTenantScope()
            ->with('restaurant')
            ->find($this->campaignId);

        // A cancelled (or deleted, or already-sent) campaign's delayed job
        // must abort silently.
        if (! $campaign || ! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Sending], true)) {
            return;
        }

        if ($this->expectedScheduledAt !== null
            && $campaign->scheduled_at?->toIso8601String() !== $this->expectedScheduledAt) {
            return;
        }

        if ($this->exceedsWeeklyCap($campaign)) {
            $this->revertToDraft($campaign, 'weekly campaign cap reached');

            return;
        }

        $pivots = $audience->query($campaign->restaurant, $campaign->audience_filter ?? [])->get();

        $ceiling = (int) config('platform.campaigns.max_recipients_per_send');

        if ($pivots->count() > $ceiling) {
            $this->revertToDraft($campaign, "audience of {$pivots->count()} exceeds the {$ceiling}-recipient ceiling");

            return;
        }

        $this->snapshotRecipients($campaign, $pivots);

        $campaign->forceFill([
            'status' => CampaignStatus::Sending,
            'recipients_count' => CampaignRecipient::query()->where('campaign_id', $campaign->id)->count(),
        ])->save();

        $recipientIds = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', CampaignRecipientStatus::Queued)
            ->pluck('id');

        if ($recipientIds->isEmpty()) {
            $campaign->forceFill(['status' => CampaignStatus::Sent, 'sent_at' => now()])->save();

            return;
        }

        $campaignId = $campaign->id;

        $jobs = $recipientIds
            ->chunk(CampaignMailer::MAX_BATCH_SIZE)
            ->map(fn (Collection $chunk): SendCampaignBatch => new SendCampaignBatch($campaignId, $chunk->values()->all()))
            ->all();

        Bus::batch($jobs)
            ->name("campaign:{$campaignId}")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($campaignId): void {
                $campaign = Campaign::withoutTenantScope()->find($campaignId);

                // Auto-pause (Session 3) can flip status mid-send; only a
                // still-sending campaign completes to sent.
                if (! $campaign || $campaign->status !== CampaignStatus::Sending) {
                    return;
                }

                $campaign->forceFill([
                    'status' => CampaignStatus::Sent,
                    'sent_at' => now(),
                    'recipients_count' => CampaignRecipient::query()->where('campaign_id', $campaignId)->count(),
                ])->save();
            })
            ->dispatch();
    }

    /**
     * Job-layer backstop for the per-restaurant weekly cap (the controller
     * enforces it again at compose time in Session 2). Counts sibling
     * campaigns that sent — or are mid-send — inside the trailing week.
     */
    protected function exceedsWeeklyCap(Campaign $campaign): bool
    {
        $max = (int) config('platform.campaigns.max_per_week');

        $recent = Campaign::withoutTenantScope()
            ->where('restaurant_id', $campaign->restaurant_id)
            ->whereKeyNot($campaign->id)
            ->where(function ($query): void {
                $query->where('status', CampaignStatus::Sending)
                    ->orWhere('sent_at', '>=', now()->subWeek());
            })
            ->count();

        return $recent >= $max;
    }

    /**
     * A cap violation at execution time returns the campaign to draft (the
     * compose work survives; the owner can resend later) rather than failing
     * the job or silently mailing a subset.
     */
    protected function revertToDraft(Campaign $campaign, string $reason): void
    {
        $campaign->forceFill(['status' => CampaignStatus::Draft, 'scheduled_at' => null])->save();

        Log::warning('SendCampaign aborted, campaign reverted to draft', [
            'campaign_id' => $campaign->id,
            'restaurant_id' => $campaign->restaurant_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Insert the audit snapshot of exactly who this send targets. Idempotent
     * via the (campaign_id, user_id) unique key, so a retried job never
     * duplicates rows already written.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, RestaurantCustomer>  $pivots
     */
    protected function snapshotRecipients(Campaign $campaign, $pivots): void
    {
        $now = now();

        $pivots->chunk(500)->each(function (Collection $chunk) use ($campaign, $now): void {
            CampaignRecipient::query()->insertOrIgnore(
                $chunk->map(fn (RestaurantCustomer $pivot): array => [
                    'campaign_id' => $campaign->id,
                    'user_id' => $pivot->user_id,
                    'email' => $pivot->user->email,
                    'status' => CampaignRecipientStatus::Queued->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all(),
            );
        });
    }
}
