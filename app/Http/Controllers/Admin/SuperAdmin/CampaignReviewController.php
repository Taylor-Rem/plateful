<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Data\CampaignData;
use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendCampaign;
use App\Models\Campaign;
use App\Models\Restaurant;
use App\Services\Campaigns\CampaignMailer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin safety console for campaigns (campaigns plan, Session 3): the
 * first-campaign review queue plus visibility into complaint auto-pauses.
 * Approval is what actually dispatches a held campaign.
 */
class CampaignReviewController extends Controller
{
    public function index(CampaignMailer $mailer): Response
    {
        $pending = Campaign::withoutTenantScope()
            ->where('status', CampaignStatus::PendingReview)
            ->with('restaurant')
            ->orderBy('id')
            ->get()
            ->map(fn (Campaign $campaign): array => array_merge($this->row($campaign), [
                'previewHtml' => $mailer->previewHtml($campaign, request()->user()),
            ]))
            ->values()
            ->all();

        $paused = Campaign::withoutTenantScope()
            ->where('status', CampaignStatus::PausedByPlatform)
            ->with('restaurant')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Campaign $campaign): array => $this->row($campaign))
            ->values()
            ->all();

        return Inertia::render('Admin/SuperAdmin/Campaigns', [
            'pending' => $pending,
            'paused' => $paused,
        ]);
    }

    public function approve(Campaign $campaign): RedirectResponse
    {
        if ($campaign->status !== CampaignStatus::PendingReview) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'This campaign is no longer awaiting review.']);

            return back();
        }

        $sendAt = $campaign->scheduled_at;

        if ($sendAt !== null && $sendAt->isFuture()) {
            $campaign->forceFill(['status' => CampaignStatus::Scheduled])->save();

            SendCampaign::dispatch($campaign->id, $campaign->scheduled_at->toIso8601String())
                ->delay($sendAt);

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Approved — the campaign will send at its scheduled time.']);
        } else {
            $campaign->forceFill(['status' => CampaignStatus::Scheduled, 'scheduled_at' => null])->save();

            SendCampaign::dispatch($campaign->id);

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Approved — the campaign is on its way.']);
        }

        return back();
    }

    public function reject(Campaign $campaign): RedirectResponse
    {
        if ($campaign->status !== CampaignStatus::PendingReview) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'This campaign is no longer awaiting review.']);

            return back();
        }

        $campaign->forceFill(['status' => CampaignStatus::Draft, 'scheduled_at' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rejected — the campaign is back in the owner\'s drafts.']);

        return back();
    }

    /**
     * Clear a complaint pause so the restaurant can send again. The paused
     * campaign itself stays halted — its remaining recipients are not resumed.
     */
    public function unpause(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->forceFill(['campaigns_paused_at' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$restaurant->name} can send campaigns again."]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Campaign $campaign): array
    {
        return [
            'campaign' => CampaignData::fromModel($campaign),
            'restaurantName' => $campaign->restaurant->name,
            'restaurantSubdomain' => $campaign->restaurant->subdomain,
            'restaurantPaused' => $campaign->restaurant->campaignsPaused(),
        ];
    }
}
