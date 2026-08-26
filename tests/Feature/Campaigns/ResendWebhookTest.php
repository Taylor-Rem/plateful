<?php

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailSuppressionReason;
use App\Jobs\SendCampaignBatch;
use App\Mail\CampaignAutoPausedMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\RestaurantCustomer;
use App\Models\SuppressedEmail;
use App\Models\User;
use App\Services\Campaigns\CampaignMailer;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/CampaignTestHelpers.php';

const RESEND_TEST_SECRET = 'resend-test-secret';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['services.resend.key' => null]);
    config(['services.resend.webhook_secret' => 'whsec_'.base64_encode(RESEND_TEST_SECRET)]);
});

/**
 * Svix-style signature over the exact JSON body postJson will send.
 *
 * @return array<string, string>
 */
function signedResendHeaders(array $payload): array
{
    $body = json_encode($payload);
    $id = 'msg_test';
    $timestamp = (string) time();
    $hash = hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", RESEND_TEST_SECRET);

    return [
        'svix-id' => $id,
        'svix-timestamp' => $timestamp,
        'svix-signature' => 'v1,'.base64_encode(pack('H*', $hash)),
    ];
}

/**
 * A sent campaign with one sent recipient carrying the given message id.
 *
 * @return array{0: Campaign, 1: CampaignRecipient, 2: User}
 */
function sentCampaignRecipient(string $messageId, array $campaignOverrides = [], bool $approved = true): array
{
    $r = liveRestaurant('marcos', campaignsApproved: $approved);
    $user = optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $c = campaign($r, array_merge([
        'status' => CampaignStatus::Sent,
        'sent_at' => now()->subHour(),
        'recipients_count' => 1,
    ], $campaignOverrides));

    $recipient = CampaignRecipient::create([
        'campaign_id' => $c->id,
        'user_id' => $user->id,
        'email' => $user->email,
        'status' => CampaignRecipientStatus::Sent,
        'resend_message_id' => $messageId,
        'sent_at' => now()->subHour(),
    ]);

    return [$c, $recipient, $user];
}

test('a bad signature is rejected and changes nothing', function () {
    [$c] = sentCampaignRecipient('re_1');

    $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 're_1']];
    $headers = signedResendHeaders($payload);
    $headers['svix-signature'] = 'v1,'.base64_encode('not-the-signature');

    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, $headers)
        ->assertStatus(400);

    expect($c->refresh()->delivered_count)->toBe(0);
});

test('a delivery increments once, stamps the recipient, and approves the restaurant', function () {
    [$c, $recipient] = sentCampaignRecipient('re_1', approved: false);

    $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 're_1']];

    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
        ->assertOk();

    expect($c->refresh()->delivered_count)->toBe(1)
        ->and($recipient->refresh()->delivered_at)->not->toBeNull()
        // The first clean delivery graduates the restaurant out of the
        // first-campaign review queue.
        ->and($c->restaurant->refresh()->campaigns_approved_at)->not->toBeNull();

    // Svix redelivery of the same event is idempotent.
    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
        ->assertOk();

    expect($c->refresh()->delivered_count)->toBe(1);
});

test('a delivery to a paused restaurant does not approve it', function () {
    [$c] = sentCampaignRecipient('re_1', approved: false);
    $c->restaurant->forceFill(['campaigns_paused_at' => now()])->save();

    $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 're_1']];

    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
        ->assertOk();

    expect($c->restaurant->refresh()->campaigns_approved_at)->toBeNull();
});

test('a hard bounce marks the recipient and suppresses the address platform-wide', function () {
    [$c, $recipient] = sentCampaignRecipient('re_1');

    $payload = [
        'type' => 'email.bounced',
        'data' => ['email_id' => 're_1', 'bounce' => ['type' => 'Permanent']],
    ];

    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
        ->assertOk();

    expect($recipient->refresh()->status)->toBe(CampaignRecipientStatus::Bounced)
        ->and($c->refresh()->bounced_count)->toBe(1);

    $suppressed = SuppressedEmail::query()->where('email', 'alice@example.test')->first();
    expect($suppressed)->not->toBeNull()
        ->and($suppressed->reason)->toBe(EmailSuppressionReason::HardBounce);

    // Redelivery is idempotent on the counter.
    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
        ->assertOk();
    expect($c->refresh()->bounced_count)->toBe(1);
});

test('a soft bounce marks the recipient but does not suppress the address', function () {
    [$c, $recipient] = sentCampaignRecipient('re_1');

    $payload = [
        'type' => 'email.bounced',
        'data' => ['email_id' => 're_1', 'bounce' => ['type' => 'Transient']],
    ];

    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
        ->assertOk();

    expect($recipient->refresh()->status)->toBe(CampaignRecipientStatus::Bounced)
        ->and(SuppressedEmail::query()->count())->toBe(0);
});

test('a complaint suppresses, opts the customer out at that restaurant, and counts', function () {
    [$c, $recipient, $user] = sentCampaignRecipient('re_1');

    $payload = ['type' => 'email.complained', 'data' => ['email_id' => 're_1']];

    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
        ->assertOk();

    expect($recipient->refresh()->status)->toBe(CampaignRecipientStatus::Complained)
        ->and($c->refresh()->complained_count)->toBe(1)
        ->and(SuppressedEmail::query()->where('email', $user->email)->firstOrFail()->reason)
        ->toBe(EmailSuppressionReason::Complaint);

    $pivot = RestaurantCustomer::query()
        ->where('restaurant_id', $c->restaurant_id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($pivot->isEmailOptedIn())->toBeFalse()
        ->and($pivot->marketing_email_opted_out_at)->not->toBeNull();
});

test('two complaints on a small send auto-pause the campaign and halt remaining batches', function () {
    Mail::fake();

    $r = liveRestaurant('marcos');
    $alice = optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    $bob = optedInCustomer($r, 'Bob Banana', 'bob@example.test');
    $cara = optedInCustomer($r, 'Cara Cherry', 'cara@example.test');

    $c = campaign($r, ['status' => CampaignStatus::Sending, 'recipients_count' => 100]);

    $rows = collect([$alice, $bob])->map(fn (User $u, int $i) => CampaignRecipient::create([
        'campaign_id' => $c->id, 'user_id' => $u->id, 'email' => $u->email,
        'status' => CampaignRecipientStatus::Sent, 'resend_message_id' => 're_'.$i, 'sent_at' => now(),
    ]));
    $queued = CampaignRecipient::create([
        'campaign_id' => $c->id, 'user_id' => $cara->id, 'email' => $cara->email,
        'status' => CampaignRecipientStatus::Queued,
    ]);

    foreach (['re_0', 're_1'] as $messageId) {
        $payload = ['type' => 'email.complained', 'data' => ['email_id' => $messageId]];
        $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
            ->assertOk();
    }

    expect($c->refresh()->status)->toBe(CampaignStatus::PausedByPlatform)
        ->and($r->refresh()->campaigns_paused_at)->not->toBeNull();

    Mail::assertQueued(CampaignAutoPausedMail::class);

    // The rest of the send halts: an in-flight batch aborts on the status guard.
    $mailer = Mockery::mock(CampaignMailer::class);
    $mailer->shouldNotReceive('send');
    (new SendCampaignBatch($c->id, [$queued->id]))->handle($mailer);

    expect($queued->refresh()->status)->toBe(CampaignRecipientStatus::Queued);
});

test('two complaints on a large send stay under the rate and do not pause', function () {
    Mail::fake();

    $r = liveRestaurant('marcos');
    $alice = optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    $bob = optedInCustomer($r, 'Bob Banana', 'bob@example.test');

    $c = campaign($r, ['status' => CampaignStatus::Sent, 'sent_at' => now(), 'recipients_count' => 1000]);

    collect([$alice, $bob])->map(fn (User $u, int $i) => CampaignRecipient::create([
        'campaign_id' => $c->id, 'user_id' => $u->id, 'email' => $u->email,
        'status' => CampaignRecipientStatus::Sent, 'resend_message_id' => 're_'.$i, 'sent_at' => now(),
    ]));

    foreach (['re_0', 're_1'] as $messageId) {
        $payload = ['type' => 'email.complained', 'data' => ['email_id' => $messageId]];
        $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
            ->assertOk();
    }

    // 2 of 1000 = 0.2%, under the 0.3% line.
    expect($c->refresh()->status)->toBe(CampaignStatus::Sent)
        ->and($r->refresh()->campaigns_paused_at)->toBeNull();

    Mail::assertNotQueued(CampaignAutoPausedMail::class);
});

test('events for unknown message ids are acknowledged and ignored', function () {
    $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 're_transactional']];

    $this->postJson('http://admin.plateful.test/webhooks/resend', $payload, signedResendHeaders($payload))
        ->assertOk();

    expect(Campaign::withoutTenantScope()->count())->toBe(0);
});
