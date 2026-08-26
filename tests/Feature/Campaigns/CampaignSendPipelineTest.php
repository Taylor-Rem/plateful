<?php

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailSuppressionReason;
use App\Enums\MarketingConsentSource;
use App\Jobs\SendCampaign;
use App\Jobs\SendCampaignBatch;
use App\Models\CampaignRecipient;
use App\Models\SuppressedEmail;
use App\Services\Campaigns\CampaignMailer;
use App\Services\MarketingConsentService;

require_once __DIR__.'/CampaignTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['services.resend.key' => null]);
});

test('a scheduled campaign sends to every eligible customer and completes', function () {
    $r = liveRestaurant('marcos');
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    optedInCustomer($r, 'Bob Banana', 'bob@example.test');
    customerPivot($r, customerUser('Norman Never', 'norman@example.test'));

    $c = campaign($r);

    SendCampaign::dispatchSync($c->id);

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Sent)
        ->and($c->sent_at)->not->toBeNull()
        ->and($c->recipients_count)->toBe(2);

    $rows = CampaignRecipient::query()->where('campaign_id', $c->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('email')->sort()->values()->all())->toBe(['alice@example.test', 'bob@example.test'])
        ->and($rows->every(fn ($row) => $row->status === CampaignRecipientStatus::Sent))->toBeTrue()
        ->and($rows->every(fn ($row) => str_starts_with((string) $row->resend_message_id, 'log-')))->toBeTrue()
        ->and($rows->every(fn ($row) => $row->sent_at !== null))->toBeTrue();
});

test('an opt-out between scheduling and execution is honored', function () {
    $r = liveRestaurant('marcos');
    $alice = optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    optedInCustomer($r, 'Bob Banana', 'bob@example.test');

    $c = campaign($r);

    // Audience resolves at execution time, so this opt-out lands in time.
    app(MarketingConsentService::class)->optOutEmail($alice, $r, MarketingConsentSource::UnsubscribeLink);

    SendCampaign::dispatchSync($c->id);

    expect(CampaignRecipient::query()->where('campaign_id', $c->id)->pluck('email')->all())
        ->toBe(['bob@example.test']);
});

test('a cancelled campaign aborts silently when its delayed job runs', function () {
    $r = liveRestaurant('marcos');
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $c = campaign($r, ['status' => CampaignStatus::Cancelled, 'scheduled_at' => now()->subMinute()]);

    SendCampaign::dispatchSync($c->id);

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Cancelled)
        ->and($c->sent_at)->toBeNull()
        ->and(CampaignRecipient::query()->where('campaign_id', $c->id)->count())->toBe(0);
});

test('an empty audience completes immediately as sent with zero recipients', function () {
    $r = liveRestaurant('marcos');

    $c = campaign($r);

    SendCampaign::dispatchSync($c->id);

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Sent)
        ->and($c->recipients_count)->toBe(0);
});

test('the weekly cap reverts an over-cap send to draft', function () {
    config(['platform.campaigns.max_per_week' => 2]);

    $r = liveRestaurant('marcos');
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    campaign($r, ['status' => CampaignStatus::Sent, 'sent_at' => now()->subDays(2)]);
    campaign($r, ['status' => CampaignStatus::Sent, 'sent_at' => now()->subDay()]);

    $c = campaign($r);
    SendCampaign::dispatchSync($c->id);

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Draft)
        ->and(CampaignRecipient::query()->where('campaign_id', $c->id)->count())->toBe(0);
});

test('campaigns sent more than a week ago do not count toward the cap', function () {
    config(['platform.campaigns.max_per_week' => 2]);

    $r = liveRestaurant('marcos');
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    campaign($r, ['status' => CampaignStatus::Sent, 'sent_at' => now()->subDays(10)]);
    campaign($r, ['status' => CampaignStatus::Sent, 'sent_at' => now()->subDays(9)]);

    $c = campaign($r);
    SendCampaign::dispatchSync($c->id);

    expect($c->refresh()->status)->toBe(CampaignStatus::Sent);
});

test('an audience over the recipient ceiling reverts to draft rather than mailing a subset', function () {
    config(['platform.campaigns.max_recipients_per_send' => 1]);

    $r = liveRestaurant('marcos');
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    optedInCustomer($r, 'Bob Banana', 'bob@example.test');

    $c = campaign($r);
    SendCampaign::dispatchSync($c->id);

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Draft)
        ->and(CampaignRecipient::query()->where('campaign_id', $c->id)->count())->toBe(0);
});

test('a retried batch job never re-sends rows already marked sent', function () {
    $r = liveRestaurant('marcos');
    $alice = optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    $bob = optedInCustomer($r, 'Bob Banana', 'bob@example.test');

    $c = campaign($r, ['status' => CampaignStatus::Sending]);

    $sentRow = CampaignRecipient::create([
        'campaign_id' => $c->id, 'user_id' => $alice->id, 'email' => $alice->email,
        'status' => CampaignRecipientStatus::Sent, 'resend_message_id' => 'log-original', 'sent_at' => now()->subMinute(),
    ]);
    $queuedRow = CampaignRecipient::create([
        'campaign_id' => $c->id, 'user_id' => $bob->id, 'email' => $bob->email,
        'status' => CampaignRecipientStatus::Queued,
    ]);

    $mailer = Mockery::mock(CampaignMailer::class);
    $mailer->shouldReceive('send')
        ->once()
        ->withArgs(fn ($campaign, $recipients) => $recipients->pluck('id')->all() === [$queuedRow->id])
        ->andReturn([$queuedRow->id => 'log-retry']);

    (new SendCampaignBatch($c->id, [$sentRow->id, $queuedRow->id]))->handle($mailer);

    expect($sentRow->refresh()->resend_message_id)->toBe('log-original')
        ->and($queuedRow->refresh()->status)->toBe(CampaignRecipientStatus::Sent)
        ->and($queuedRow->resend_message_id)->toBe('log-retry');
});

test('a batch re-checks suppression and fails the row instead of sending', function () {
    $r = liveRestaurant('marcos');
    $alice = optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $c = campaign($r, ['status' => CampaignStatus::Sending]);
    $row = CampaignRecipient::create([
        'campaign_id' => $c->id, 'user_id' => $alice->id, 'email' => $alice->email,
        'status' => CampaignRecipientStatus::Queued,
    ]);

    // Suppression landed after the snapshot (e.g. a bounce from an earlier batch).
    SuppressedEmail::create(['email' => $alice->email, 'reason' => EmailSuppressionReason::HardBounce, 'created_at' => now()]);

    $mailer = Mockery::mock(CampaignMailer::class);
    $mailer->shouldNotReceive('send');

    (new SendCampaignBatch($c->id, [$row->id]))->handle($mailer);

    expect($row->refresh()->status)->toBe(CampaignRecipientStatus::Failed)
        ->and($row->resend_message_id)->toBeNull();
});

test('a batch aborts without sending once the campaign is no longer sending', function () {
    $r = liveRestaurant('marcos');
    $alice = optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $c = campaign($r, ['status' => CampaignStatus::PausedByPlatform]);
    $row = CampaignRecipient::create([
        'campaign_id' => $c->id, 'user_id' => $alice->id, 'email' => $alice->email,
        'status' => CampaignRecipientStatus::Queued,
    ]);

    $mailer = Mockery::mock(CampaignMailer::class);
    $mailer->shouldNotReceive('send');

    (new SendCampaignBatch($c->id, [$row->id]))->handle($mailer);

    expect($row->refresh()->status)->toBe(CampaignRecipientStatus::Queued);
});

test('a re-run SendCampaign does not duplicate recipient snapshots', function () {
    $r = liveRestaurant('marcos');
    optedInCustomer($r, 'Alice Apple', 'alice@example.test');

    $c = campaign($r);
    SendCampaign::dispatchSync($c->id);

    // Force it back to sending and run again — the unique (campaign, user)
    // key keeps the snapshot stable and already-sent rows are untouched.
    $c->refresh()->forceFill(['status' => CampaignStatus::Sending])->save();
    SendCampaign::dispatchSync($c->id);

    expect(CampaignRecipient::query()->where('campaign_id', $c->id)->count())->toBe(1);
});
