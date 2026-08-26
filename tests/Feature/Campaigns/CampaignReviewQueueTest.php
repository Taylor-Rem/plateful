<?php

use App\Enums\CampaignStatus;
use App\Jobs\SendCampaign;
use App\Mail\CampaignReviewSubmittedMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/CampaignTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['services.resend.key' => null]);
});

/**
 * @return array<string, mixed>
 */
function reviewComposePayload(array $overrides = []): array
{
    return array_merge([
        'subject' => 'Slow Tuesday: half-price pies',
        'preheader' => null,
        'headline' => 'Half-price pies this Tuesday',
        'body' => 'Come hungry. Leave happy.',
        'offer_callout' => null,
        'cta_label' => null,
        'cta_url' => null,
        'audience' => ['type' => 'all'],
        'action' => 'send',
    ], $overrides);
}

test('a first campaign is held for review instead of sending, and the platform is pinged', function () {
    Mail::fake();

    $r = liveRestaurant('marcos', campaignsApproved: false);
    $admin = adminForRestaurant($r);
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns", reviewComposePayload())
        ->assertRedirect();

    $c = Campaign::withoutTenantScope()->firstOrFail();
    expect($c->status)->toBe(CampaignStatus::PendingReview)
        ->and(CampaignRecipient::query()->count())->toBe(0);

    Mail::assertQueued(CampaignReviewSubmittedMail::class, fn (CampaignReviewSubmittedMail $mail) => $mail->campaign->id === $c->id
        && $mail->hasTo(config('mail.senders.support')));
});

test('a scheduled first campaign is held with its requested send time', function () {
    Mail::fake();
    Queue::fake();

    $r = liveRestaurant('marcos', campaignsApproved: false);
    $admin = adminForRestaurant($r);

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns", reviewComposePayload([
            'action' => 'schedule',
            'scheduled_at' => '2030-06-01T10:00',
        ]))
        ->assertRedirect();

    $c = Campaign::withoutTenantScope()->firstOrFail();
    expect($c->status)->toBe(CampaignStatus::PendingReview)
        ->and($c->scheduled_at)->not->toBeNull();

    Queue::assertNothingPushed();
});

test('a restaurant already campaigns-approved skips the queue', function () {
    $r = liveRestaurant('marcos');
    $admin = adminForRestaurant($r);
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns", reviewComposePayload())
        ->assertRedirect();

    expect(Campaign::withoutTenantScope()->firstOrFail()->status)->toBe(CampaignStatus::Sent);
});

test('the super-admin queue lists pending campaigns with a preview', function () {
    $r = liveRestaurant('marcos', campaignsApproved: false);
    campaign($r, ['status' => CampaignStatus::PendingReview]);

    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)
        ->get('http://admin.plateful.test/super/campaigns')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/SuperAdmin/Campaigns')
            ->has('pending', 1)
            ->where('pending.0.restaurantSubdomain', 'marcos')
            ->where('pending.0.campaign.subject', 'Slow Tuesday: half-price pies')
            ->has('pending.0.previewHtml')
            ->has('paused', 0)
        );
});

test('tenant admins cannot reach the super-admin queue', function () {
    $r = liveRestaurant('marcos');
    $admin = adminForRestaurant($r);

    $this->actingAs($admin)
        ->get('http://admin.plateful.test/super/campaigns')
        ->assertForbidden();
});

test('approval dispatches the held campaign', function () {
    $r = liveRestaurant('marcos', campaignsApproved: false);
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    $c = campaign($r, ['status' => CampaignStatus::PendingReview]);

    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)
        ->post("http://admin.plateful.test/super/campaigns/{$c->id}/approve")
        ->assertRedirect();

    // Sync queue: the whole pipeline ran on approval.
    expect($c->refresh()->status)->toBe(CampaignStatus::Sent)
        ->and(CampaignRecipient::query()->where('campaign_id', $c->id)->count())->toBe(1);
});

test('approving a campaign scheduled for the future keeps its send time', function () {
    Queue::fake();

    $r = liveRestaurant('marcos', campaignsApproved: false);
    $c = campaign($r, [
        'status' => CampaignStatus::PendingReview,
        'scheduled_at' => now()->addDays(2),
    ]);

    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)
        ->post("http://admin.plateful.test/super/campaigns/{$c->id}/approve")
        ->assertRedirect();

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Scheduled);

    Queue::assertPushed(SendCampaign::class, fn (SendCampaign $job) => $job->campaignId === $c->id
        && $job->expectedScheduledAt === $c->scheduled_at->toIso8601String()
        && $job->delay !== null);
});

test('rejection returns the campaign to draft', function () {
    $r = liveRestaurant('marcos', campaignsApproved: false);
    $c = campaign($r, ['status' => CampaignStatus::PendingReview, 'scheduled_at' => now()->addDay()]);

    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)
        ->post("http://admin.plateful.test/super/campaigns/{$c->id}/reject")
        ->assertRedirect();

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Draft)
        ->and($c->scheduled_at)->toBeNull();
});

test('approving a campaign that is no longer pending does nothing', function () {
    Queue::fake();

    $r = liveRestaurant('marcos', campaignsApproved: false);
    $c = campaign($r, ['status' => CampaignStatus::Cancelled]);

    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)
        ->post("http://admin.plateful.test/super/campaigns/{$c->id}/approve")
        ->assertRedirect();

    expect($c->refresh()->status)->toBe(CampaignStatus::Cancelled);
    Queue::assertNothingPushed();
});

test('the owner can withdraw a campaign from review', function () {
    $r = liveRestaurant('marcos', campaignsApproved: false);
    $admin = adminForRestaurant($r);
    $c = campaign($r, ['status' => CampaignStatus::PendingReview]);

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns/{$c->id}/cancel")
        ->assertRedirect();

    expect($c->refresh()->status)->toBe(CampaignStatus::Cancelled);
});

test('a paused restaurant cannot send from the controller', function () {
    $r = liveRestaurant('marcos');
    $r->forceFill(['campaigns_paused_at' => now()])->save();
    $admin = adminForRestaurant($r);
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns", reviewComposePayload())
        ->assertRedirect();

    expect(Campaign::withoutTenantScope()->firstOrFail()->status)->toBe(CampaignStatus::Draft)
        ->and(CampaignRecipient::query()->count())->toBe(0);
});

test('the job gate re-check reverts a scheduled campaign when the restaurant is paused', function () {
    $r = liveRestaurant('marcos');
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    $c = campaign($r, ['status' => CampaignStatus::Scheduled]);

    // The pause lands between scheduling and the delayed job firing.
    $r->forceFill(['campaigns_paused_at' => now()])->save();

    SendCampaign::dispatchSync($c->id);

    expect($c->refresh()->status)->toBe(CampaignStatus::Draft)
        ->and(CampaignRecipient::query()->count())->toBe(0);
});

test('a super admin can resume a paused restaurant', function () {
    $r = liveRestaurant('marcos');
    $r->forceFill(['campaigns_paused_at' => now()])->save();
    campaign($r, ['status' => CampaignStatus::PausedByPlatform, 'recipients_count' => 50, 'complained_count' => 2]);

    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)
        ->post("http://admin.plateful.test/super/campaigns/paused/{$r->subdomain}/resume")
        ->assertRedirect();

    expect($r->refresh()->campaigns_paused_at)->toBeNull();
});
