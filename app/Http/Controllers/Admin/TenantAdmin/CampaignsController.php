<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\CampaignData;
use App\Data\RestaurantData;
use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantAdmin\CampaignFieldsRequest;
use App\Http\Requests\Admin\TenantAdmin\StoreCampaignRequest;
use App\Jobs\SendCampaign;
use App\Mail\CampaignReviewSubmittedMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignMailer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compose-and-send for email campaigns (campaigns plan, Session 2). Sending
 * itself is the Session 1 job pipeline; this controller owns the drafts, the
 * status transitions, and the user-facing enforcement of the send gate and
 * caps (the SendCampaign job re-checks both as a backstop).
 */
class CampaignsController extends Controller
{
    /**
     * Statuses a campaign can be (re)sent or (re)scheduled from. A stale
     * delayed dispatch from a superseded schedule is neutralized by the
     * expectedScheduledAt stamp on the job.
     *
     * @var array<int, CampaignStatus>
     */
    protected const SENDABLE_STATUSES = [
        CampaignStatus::Draft,
        CampaignStatus::Scheduled,
        CampaignStatus::Cancelled,
    ];

    public function index(Restaurant $restaurant): Response
    {
        $campaigns = Campaign::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Campaign $campaign) => CampaignData::fromModel($campaign))
            ->values()
            ->all();

        return Inertia::render('Admin/TenantAdmin/Campaigns/Index', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'campaigns' => $campaigns,
            'optedInCount' => $this->optedInCount($restaurant),
        ]);
    }

    public function create(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Campaigns/Create', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'optedInCount' => $this->optedInCount($restaurant),
            'sendBlocker' => $this->sendGateBlocker($restaurant),
        ]);
    }

    public function store(StoreCampaignRequest $request, Restaurant $restaurant, CampaignAudience $audience): RedirectResponse
    {
        $campaign = Campaign::create(array_merge($request->campaignFields(), [
            'restaurant_id' => $restaurant->id,
            'status' => CampaignStatus::Draft,
        ]));

        if ($request->string('action')->toString() === 'send') {
            return $this->dispatchNow($restaurant, $campaign, $audience);
        }

        if ($request->string('action')->toString() === 'schedule') {
            return $this->dispatchScheduled($restaurant, $campaign, $audience, (string) $request->input('scheduled_at'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft saved.']);

        return $this->backToShow($restaurant, $campaign);
    }

    public function show(Restaurant $restaurant, Campaign $campaign, CampaignMailer $mailer): Response
    {
        $campaign->setRelation('restaurant', $restaurant);

        return Inertia::render('Admin/TenantAdmin/Campaigns/Show', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'campaign' => CampaignData::fromModel($campaign),
            'previewHtml' => $mailer->previewHtml($campaign, request()->user()),
            'sendBlocker' => $this->sendGateBlocker($restaurant),
        ]);
    }

    /**
     * Live recipient count for the compose page's audience picker.
     */
    public function count(Request $request, Restaurant $restaurant, CampaignAudience $audience): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['all', 'lapsed', 'regulars'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'min_orders' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filter = match ($validated['type']) {
            'lapsed' => ['type' => 'lapsed', 'days' => (int) ($validated['days'] ?? 30)],
            'regulars' => ['type' => 'regulars', 'min_orders' => (int) ($validated['min_orders'] ?? 3)],
            default => ['type' => 'all'],
        };

        return response()->json(['count' => $audience->count($restaurant, $filter)]);
    }

    /**
     * Server-render the email for the compose page's preview iframe, from
     * unsaved compose state. The sample recipient is the viewing admin.
     */
    public function preview(CampaignFieldsRequest $request, Restaurant $restaurant, CampaignMailer $mailer): JsonResponse
    {
        $campaign = $this->ephemeralCampaign($request, $restaurant);

        return response()->json([
            'html' => $mailer->previewHtml($campaign, $request->user()),
        ]);
    }

    /**
     * Send the composed email to the logged-in admin only. Deliberately never
     * touches campaigns/campaign_recipients/counters — it is a preview in a
     * real inbox, not a send.
     */
    public function test(CampaignFieldsRequest $request, Restaurant $restaurant, CampaignMailer $mailer): RedirectResponse
    {
        $campaign = $this->ephemeralCampaign($request, $restaurant);
        $campaign->subject = '[Test] '.$campaign->subject;

        $mailer->send($campaign, new Collection([$this->sampleRecipient($request->user())]));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Test email sent to {$request->user()->email}."]);

        return back();
    }

    public function send(Restaurant $restaurant, Campaign $campaign, CampaignAudience $audience): RedirectResponse
    {
        return $this->dispatchNow($restaurant, $campaign, $audience);
    }

    public function schedule(Request $request, Restaurant $restaurant, Campaign $campaign, CampaignAudience $audience): RedirectResponse
    {
        $validated = $request->validate(['scheduled_at' => ['required', 'date']]);

        return $this->dispatchScheduled($restaurant, $campaign, $audience, (string) $validated['scheduled_at']);
    }

    public function cancel(Restaurant $restaurant, Campaign $campaign): RedirectResponse
    {
        if (! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::PendingReview], true)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Only a scheduled or in-review campaign can be cancelled.']);

            return back();
        }

        $campaign->forceFill(['status' => CampaignStatus::Cancelled])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Campaign cancelled.']);

        return back();
    }

    protected function dispatchNow(Restaurant $restaurant, Campaign $campaign, CampaignAudience $audience): RedirectResponse
    {
        if ($blocker = $this->sendBlocker($restaurant, $campaign, $audience)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $blocker]);

            return $this->backToShow($restaurant, $campaign);
        }

        if ($restaurant->needsFirstCampaignReview()) {
            return $this->holdForReview($restaurant, $campaign, scheduledAt: null);
        }

        $campaign->forceFill(['status' => CampaignStatus::Scheduled, 'scheduled_at' => null])->save();

        SendCampaign::dispatch($campaign->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your campaign is on its way.']);

        return $this->backToShow($restaurant, $campaign);
    }

    protected function dispatchScheduled(Restaurant $restaurant, Campaign $campaign, CampaignAudience $audience, string $scheduledAtInput): RedirectResponse
    {
        // The owner picks a wall-clock time at their restaurant; convert to
        // the app timezone before storing and comparing.
        $scheduledAt = CarbonImmutable::parse($scheduledAtInput, $restaurant->timezone ?: 'UTC')
            ->setTimezone(config('app.timezone'));

        if ($scheduledAt->isPast()) {
            return $this->backToShow($restaurant, $campaign)
                ->withErrors(['scheduled_at' => 'The scheduled time must be in the future.']);
        }

        if ($blocker = $this->sendBlocker($restaurant, $campaign, $audience)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $blocker]);

            return $this->backToShow($restaurant, $campaign);
        }

        if ($restaurant->needsFirstCampaignReview()) {
            return $this->holdForReview($restaurant, $campaign, $scheduledAt);
        }

        $campaign->forceFill(['status' => CampaignStatus::Scheduled, 'scheduled_at' => $scheduledAt])->save();

        SendCampaign::dispatch($campaign->id, $campaign->scheduled_at->toIso8601String())
            ->delay($scheduledAt);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Campaign scheduled.']);

        return $this->backToShow($restaurant, $campaign);
    }

    /**
     * First-campaign review queue (Session 3): the campaign is held as
     * pending_review instead of dispatching, and the platform is pinged. A
     * super admin approving it performs the actual dispatch.
     */
    protected function holdForReview(Restaurant $restaurant, Campaign $campaign, ?CarbonImmutable $scheduledAt): RedirectResponse
    {
        $campaign->forceFill([
            'status' => CampaignStatus::PendingReview,
            'scheduled_at' => $scheduledAt,
        ])->save();

        $campaign->setRelation('restaurant', $restaurant);
        Mail::to(config('mail.senders.support'))->queue(new CampaignReviewSubmittedMail($campaign));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'First campaigns get a quick review by Plateful before sending — usually same day. We\'ll take it from here.',
        ]);

        return $this->backToShow($restaurant, $campaign);
    }

    /**
     * The user-facing reason this campaign cannot be sent right now, or null.
     */
    protected function sendBlocker(Restaurant $restaurant, Campaign $campaign, CampaignAudience $audience): ?string
    {
        if (! in_array($campaign->status, self::SENDABLE_STATUSES, true)) {
            return 'This campaign has already been sent or is currently sending.';
        }

        if ($gate = $this->sendGateBlocker($restaurant)) {
            return $gate;
        }

        $maxPerWeek = (int) config('platform.campaigns.max_per_week');

        $recentCount = Campaign::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->whereKeyNot($campaign->id)
            ->where(function ($query): void {
                $query->where('status', CampaignStatus::Sending)
                    ->orWhere('sent_at', '>=', now()->subWeek());
            })
            ->count();

        if ($recentCount >= $maxPerWeek) {
            return "You've reached the limit of {$maxPerWeek} campaigns per week.";
        }

        $ceiling = (int) config('platform.campaigns.max_recipients_per_send');
        $audienceCount = $audience->count($restaurant, $campaign->audience_filter ?? ['type' => 'all']);

        if ($audienceCount > $ceiling) {
            return "This audience ({$audienceCount} customers) exceeds the {$ceiling}-recipient limit per send.";
        }

        return null;
    }

    /**
     * The restaurant-level sending gate: live storefront + Stripe-ready.
     */
    protected function sendGateBlocker(Restaurant $restaurant): ?string
    {
        if ($restaurant->campaignsPaused()) {
            return 'Campaign sending is paused pending a review by Plateful. Contact support if you have questions.';
        }

        if (! $restaurant->isLive() || ! $restaurant->isStripeReady()) {
            return 'Your restaurant must be live and payments-ready before sending campaigns.';
        }

        return null;
    }

    protected function backToShow(Restaurant $restaurant, Campaign $campaign): RedirectResponse
    {
        return to_route('admin.restaurant.campaigns.show', [$restaurant->subdomain, $campaign->id]);
    }

    /**
     * Same opted-in count as the Customers page header — the number the
     * index's empty state sells the feature with.
     */
    protected function optedInCount(Restaurant $restaurant): int
    {
        return RestaurantCustomer::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereHas('user')
            ->emailOptedIn()
            ->count();
    }

    /**
     * An unsaved Campaign carrying compose state, for preview and test-send.
     */
    protected function ephemeralCampaign(CampaignFieldsRequest $request, Restaurant $restaurant): Campaign
    {
        $campaign = new Campaign($request->campaignFields());
        $campaign->setRelation('restaurant', $restaurant);

        return $campaign;
    }

    /**
     * An unsaved recipient row pointing at the viewing admin — gives the
     * template a real unsubscribe URL without touching campaign_recipients.
     */
    protected function sampleRecipient(User $user): CampaignRecipient
    {
        $recipient = new CampaignRecipient(['email' => $user->email]);
        $recipient->setRelation('user', $user);

        return $recipient;
    }
}
