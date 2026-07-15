<?php

use App\Enums\DeliveryFeeStrategy;
use App\Enums\DeliveryMode;
use App\Enums\SelfDeliveryTipRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    $this->restaurant = adminOrderRestaurant('setco');
    $this->owner = adminForRestaurant($this->restaurant);
});

function settingsUrl(): string
{
    return 'http://admin.plateful.test/'.test()->restaurant->subdomain.'/settings/delivery';
}

/**
 * @return array<string, mixed>
 */
function settingsBody(array $overrides = []): array
{
    return array_merge([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty->value,
        'delivery_fee' => '4.99',
        'delivery_fee_strategy' => DeliveryFeeStrategy::PassThrough->value,
        'prep_time_minutes' => 12,
        'self_delivery_tip_recipient' => SelfDeliveryTipRecipient::Driver->value,
        'delivery_fallback_action' => 'try_next_provider',
    ], $overrides);
}

test('an owner can finally turn delivery on', function () {
    // Every one of these columns existed with no UI at all before now.
    $this->actingAs($this->owner)
        ->put(settingsUrl(), settingsBody())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $r = $this->restaurant->fresh();
    expect($r->delivery_enabled)->toBeTrue();
    expect($r->delivery_mode)->toBe(DeliveryMode::ThirdParty);
    expect($r->delivery_fee_strategy)->toBe(DeliveryFeeStrategy::PassThrough);
    expect($r->prep_time_minutes)->toBe(12);
    expect($r->delivery_fee_cents)->toBe(499);
});

test('turning delivery on without choosing who delivers is rejected', function () {
    // Without a mode the dispatcher silently treats the restaurant as
    // third-party — an owner would get couriers they never asked for.
    $this->actingAs($this->owner)
        ->put(settingsUrl(), settingsBody(['delivery_mode' => null]))
        ->assertSessionHasErrors('delivery_mode');

    expect($this->restaurant->fresh()->delivery_enabled)->toBeFalsy();
});

test('delivery can be switched off without choosing a mode', function () {
    $this->actingAs($this->owner)
        ->put(settingsUrl(), settingsBody(['delivery_enabled' => false, 'delivery_mode' => null]))
        ->assertSessionHasNoErrors();

    expect($this->restaurant->fresh()->delivery_enabled)->toBeFalse();
});

test('the dropped split strategy is rejected', function () {
    $this->actingAs($this->owner)
        ->put(settingsUrl(), settingsBody(['delivery_fee_strategy' => 'split']))
        ->assertSessionHasErrors('delivery_fee_strategy');
});

test('prep time is bounded, but zero is allowed', function () {
    $this->actingAs($this->owner)
        ->put(settingsUrl(), settingsBody(['prep_time_minutes' => 0]))
        ->assertSessionHasNoErrors();

    expect($this->restaurant->fresh()->prep_time_minutes)->toBe(0);

    $this->actingAs($this->owner)
        ->put(settingsUrl(), settingsBody(['prep_time_minutes' => 500]))
        ->assertSessionHasErrors('prep_time_minutes');
});

test('the settings page exposes current values and the webhook url', function () {
    $this->restaurant->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery,
        'prep_time_minutes' => 25,
    ]);

    $this->actingAs($this->owner)
        ->get(settingsUrl())
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('settings.deliveryEnabled', true)
            ->where('settings.deliveryMode', 'self')
            ->where('settings.prepTimeMinutes', 25)
            ->where('webhookUrl', 'http://admin.plateful.test/webhooks/uber')
            ->has('options.modes', 2)
            // Split is gone: two strategies, two products.
            ->has('options.feeStrategies', 2));
});

test('an admin of another restaurant cannot change these settings', function () {
    $theirs = adminOrderRestaurant('setother');
    $outsider = adminForRestaurant($theirs, 'outsider@m.test');

    $this->actingAs($outsider)
        ->put(settingsUrl(), settingsBody())
        ->assertForbidden();

    expect($this->restaurant->fresh()->delivery_enabled)->toBeFalsy();
});
