<?php

use App\Enums\CampaignStatus;
use App\Jobs\SendCampaign;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignAudience;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/CampaignTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['services.resend.key' => null]);
    // Deterministic sends: no random spot-check holds in these tests.
    config(['platform.campaigns.review.spot_check_rate' => 0]);
});

/**
 * @return array<string, mixed>
 */
function composePayload(array $overrides = []): array
{
    return array_merge([
        'subject' => 'Slow Tuesday: half-price pies',
        'preheader' => 'This Tuesday only',
        'headline' => 'Half-price pies this Tuesday',
        'body' => 'Come hungry. Leave happy.',
        'offer_callout' => '50% off all pies',
        'cta_label' => null,
        'cta_url' => null,
        'audience' => ['type' => 'all'],
        'action' => 'save',
    ], $overrides);
}

test('index lists campaigns and sells the empty state with the opted-in count', function () {
    $r = liveRestaurant();
    $admin = adminForRestaurant($r);

    optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    optedInCustomer($r, 'Bob Banana', 'bob@example.test');
    customerPivot($r, customerUser('Norman Never', 'norman@example.test'));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/campaigns")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/TenantAdmin/Campaigns/Index')
            ->has('campaigns', 0)
            ->where('optedInCount', 2)
        );
});

test('campaigns of another restaurant are never visible or sendable', function () {
    $marcos = liveRestaurant('marcos');
    $bobs = liveRestaurant('bobs');
    $admin = adminForRestaurant($marcos);

    $foreign = campaign($bobs, ['status' => CampaignStatus::Draft]);

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$marcos->subdomain}/campaigns")
        ->assertInertia(fn ($p) => $p->has('campaigns', 0));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$marcos->subdomain}/campaigns/{$foreign->id}")
        ->assertNotFound();

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$marcos->subdomain}/campaigns/{$foreign->id}/send")
        ->assertNotFound();

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$bobs->subdomain}/campaigns")
        ->assertForbidden();

    expect($foreign->refresh()->status)->toBe(CampaignStatus::Draft);
});

test('staff members cannot access campaigns', function () {
    $r = liveRestaurant();

    $staff = customerUser('Staffer', 'staff@m.test');
    $staff->restaurants()->attach($r->id, ['role' => 'staff']);

    $this->actingAs($staff)
        ->get("http://admin.plateful.test/{$r->subdomain}/campaigns")
        ->assertForbidden();
});

test('saving a draft stores the campaign and redirects to its page', function () {
    $r = liveRestaurant();
    $admin = adminForRestaurant($r);

    $response = $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns", composePayload());

    $c = Campaign::withoutTenantScope()->firstOrFail();
    $response->assertRedirect("http://admin.plateful.test/{$r->subdomain}/campaigns/{$c->id}");

    expect($c->status)->toBe(CampaignStatus::Draft)
        ->and($c->restaurant_id)->toBe($r->id)
        ->and($c->audience_filter)->toBe(['type' => 'all']);
});

test('composing with send now runs the whole pipeline', function () {
    $r = liveRestaurant();
    $admin = adminForRestaurant($r);
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns", composePayload(['action' => 'send']))
        ->assertRedirect();

    $c = Campaign::withoutTenantScope()->firstOrFail();
    expect($c->status)->toBe(CampaignStatus::Sent)
        ->and($c->recipients_count)->toBe(1)
        ->and(CampaignRecipient::query()->where('campaign_id', $c->id)->count())->toBe(1);
});

test('a restaurant that is not live and Stripe-ready cannot send', function () {
    $r = adminOrderRestaurant();
    $admin = adminForRestaurant($r);
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns", composePayload(['action' => 'send']))
        ->assertRedirect();

    $c = Campaign::withoutTenantScope()->firstOrFail();
    expect($c->status)->toBe(CampaignStatus::Draft)
        ->and(CampaignRecipient::query()->count())->toBe(0);
});

test('draft to scheduled to cancelled, converting the wall-clock time from the restaurant timezone', function () {
    Queue::fake();

    $r = liveRestaurant();
    $r->forceFill(['timezone' => 'America/Denver'])->save();
    $admin = adminForRestaurant($r);

    $c = campaign($r, ['status' => CampaignStatus::Draft]);

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns/{$c->id}/schedule", [
            'scheduled_at' => '2030-06-01T10:00',
        ])
        ->assertRedirect();

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Scheduled)
        // 10:00 in Denver (MDT, UTC-6) is 16:00 UTC.
        ->and($c->scheduled_at->toIso8601String())->toBe('2030-06-01T16:00:00+00:00');

    Queue::assertPushed(SendCampaign::class, fn (SendCampaign $job) => $job->campaignId === $c->id
        && $job->expectedScheduledAt === $c->scheduled_at->toIso8601String());

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns/{$c->id}/cancel")
        ->assertRedirect();

    expect($c->refresh()->status)->toBe(CampaignStatus::Cancelled);
});

test('a scheduled time in the past is rejected', function () {
    Queue::fake();

    $r = liveRestaurant();
    $admin = adminForRestaurant($r);
    $c = campaign($r, ['status' => CampaignStatus::Draft]);

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns/{$c->id}/schedule", [
            'scheduled_at' => '2020-01-01T10:00',
        ])
        ->assertSessionHasErrors('scheduled_at');

    expect($c->refresh()->status)->toBe(CampaignStatus::Draft);
    Queue::assertNothingPushed();
});

test('a sent campaign cannot be sent again', function () {
    Queue::fake();

    $r = liveRestaurant();
    $admin = adminForRestaurant($r);
    $c = campaign($r, ['status' => CampaignStatus::Sent, 'sent_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns/{$c->id}/send")
        ->assertRedirect();

    expect($c->refresh()->status)->toBe(CampaignStatus::Sent);
    Queue::assertNothingPushed();
});

test('only a scheduled campaign can be cancelled', function () {
    $r = liveRestaurant();
    $admin = adminForRestaurant($r);
    $c = campaign($r, ['status' => CampaignStatus::Draft]);

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns/{$c->id}/cancel")
        ->assertRedirect();

    expect($c->refresh()->status)->toBe(CampaignStatus::Draft);
});

test('the live recipient count matches the audience resolver', function () {
    $r = liveRestaurant();
    $admin = adminForRestaurant($r);

    optedInCustomer($r, 'Regular Ruth', 'ruth@example.test', ['total_orders' => 10]);
    optedInCustomer($r, 'Once Owen', 'owen@example.test', ['total_orders' => 1]);

    $expected = app(CampaignAudience::class)->count($r, ['type' => 'regulars', 'min_orders' => 5]);

    $this->actingAs($admin)
        ->getJson("http://admin.plateful.test/{$r->subdomain}/campaigns/recipient-count?type=regulars&min_orders=5")
        ->assertOk()
        ->assertJson(['count' => $expected]);

    expect($expected)->toBe(1);
});

test('a test send reaches only the admin and creates no campaign or recipient rows', function () {
    $r = liveRestaurant();
    $admin = adminForRestaurant($r);
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $payload = composePayload();
    unset($payload['action']);

    $this->actingAs($admin)
        ->post("http://admin.plateful.test/{$r->subdomain}/campaigns/test", $payload)
        ->assertRedirect();

    expect(Campaign::withoutTenantScope()->count())->toBe(0)
        ->and(CampaignRecipient::query()->count())->toBe(0);
});

test('the preview endpoint renders the composed email with the compliance footer', function () {
    $r = liveRestaurant();
    $admin = adminForRestaurant($r);

    $payload = composePayload();
    unset($payload['action']);

    $response = $this->actingAs($admin)
        ->postJson("http://admin.plateful.test/{$r->subdomain}/campaigns/preview", $payload)
        ->assertOk();

    $html = $response->json('html');
    expect($html)->toContain('Half-price pies this Tuesday')
        ->toContain('Sent via Plateful')
        ->toContain('Unsubscribe');
});
