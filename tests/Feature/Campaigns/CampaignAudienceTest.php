<?php

use App\Enums\EmailSuppressionReason;
use App\Models\SuppressedEmail;
use App\Services\Campaigns\CampaignAudience;

require_once __DIR__.'/CampaignTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    $this->audience = app(CampaignAudience::class);
});

test('only opted-in customers are eligible', function () {
    $r = adminOrderRestaurant('marcos');

    optedInCustomer($r, 'Alice In', 'alice@example.test');
    customerPivot($r, customerUser('Norman Never', 'norman@example.test'));
    customerPivot($r, customerUser('Olive Out', 'olive@example.test'), [
        'marketing_email_opted_in_at' => now()->subDays(5),
        'marketing_email_opted_out_at' => now()->subDay(),
    ]);

    $emails = $this->audience->query($r, ['type' => 'all'])->get()->pluck('user.email');

    expect($emails->all())->toBe(['alice@example.test'])
        ->and($this->audience->count($r, ['type' => 'all']))->toBe(1);
});

test('soft-deleted users are excluded', function () {
    $r = adminOrderRestaurant('marcos');

    $gone = optedInCustomer($r, 'Gone Gal', 'gone@example.test');
    $gone->delete();
    optedInCustomer($r, 'Here Guy', 'here@example.test');

    $emails = $this->audience->query($r, ['type' => 'all'])->get()->pluck('user.email');

    expect($emails->all())->toBe(['here@example.test']);
});

test('suppressed emails are excluded', function () {
    $r = adminOrderRestaurant('marcos');

    optedInCustomer($r, 'Bounced Bob', 'bounced@example.test');
    optedInCustomer($r, 'Clean Cleo', 'clean@example.test');
    SuppressedEmail::create([
        'email' => 'bounced@example.test',
        'reason' => EmailSuppressionReason::HardBounce,
        'created_at' => now(),
    ]);

    $emails = $this->audience->query($r, ['type' => 'all'])->get()->pluck('user.email');

    expect($emails->all())->toBe(['clean@example.test']);
});

test('customers of another restaurant are never included', function () {
    $marcos = adminOrderRestaurant('marcos');
    $bobs = adminOrderRestaurant('bobs');

    optedInCustomer($bobs, 'Bobs Customer', 'bobsc@example.test');

    expect($this->audience->count($marcos, ['type' => 'all']))->toBe(0);
});

test('lapsed filter keeps only customers whose last order is older than N days', function () {
    $r = adminOrderRestaurant('marcos');

    optedInCustomer($r, 'Lapsed Lou', 'lapsed@example.test', ['last_ordered_at' => now()->subDays(45)]);
    optedInCustomer($r, 'Recent Rae', 'recent@example.test', ['last_ordered_at' => now()->subDays(3)]);

    $emails = $this->audience->query($r, ['type' => 'lapsed', 'days' => 30])->get()->pluck('user.email');

    expect($emails->all())->toBe(['lapsed@example.test']);
});

test('regulars filter keeps only customers with at least N orders', function () {
    $r = adminOrderRestaurant('marcos');

    optedInCustomer($r, 'Regular Ruth', 'ruth@example.test', ['total_orders' => 10]);
    optedInCustomer($r, 'Once Owen', 'owen@example.test', ['total_orders' => 1]);

    $emails = $this->audience->query($r, ['type' => 'regulars', 'min_orders' => 5])->get()->pluck('user.email');

    expect($emails->all())->toBe(['ruth@example.test']);
});
