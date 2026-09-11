<?php

use App\Models\DeviceToken;
use App\Models\User;

require_once __DIR__.'/ApiHelpers.php';

test('a device registers once, refreshes on re-registration, and re-homes when another account signs in', function () {
    $user = User::factory()->create();
    $token = 'ExponentPushToken[abc123XYZ]';

    $this->withToken(apiTokenFor($user))->postJson(API_BASE.'/me/devices', ['token' => $token, 'platform' => 'ios', 'device_name' => 'iPhone'])
        ->assertCreated()
        ->assertJsonPath('data.token', $token)
        ->assertJsonPath('data.platform', 'ios')
        ->assertJsonPath('data.deviceName', 'iPhone');

    $this->withToken(apiTokenFor($user))->postJson(API_BASE.'/me/devices', ['token' => $token, 'platform' => 'ios', 'device_name' => 'Ada\'s iPhone'])
        ->assertOk()->assertJsonPath('data.deviceName', 'Ada\'s iPhone');

    expect(DeviceToken::count())->toBe(1);

    forgetApiGuards();
    $other = User::factory()->create();
    $this->withToken(apiTokenFor($other))->postJson(API_BASE.'/me/devices', ['token' => $token, 'platform' => 'ios'])->assertOk();

    expect(DeviceToken::count())->toBe(1)
        ->and(DeviceToken::first()->user_id)->toBe($other->id)
        ->and($user->routeNotificationForExpo())->toBe([])
        ->and($other->routeNotificationForExpo())->toBe([$token]);
});

test('only Expo push tokens are accepted and a device can be removed', function () {
    $user = User::factory()->create();
    $bearer = apiTokenFor($user);

    $this->withToken($bearer)->postJson(API_BASE.'/me/devices', ['token' => 'apns-raw-token', 'platform' => 'ios'])
        ->assertUnprocessable()->assertJsonValidationErrors(['token']);
    $this->withToken($bearer)->postJson(API_BASE.'/me/devices', ['token' => 'ExponentPushToken[ok]', 'platform' => 'web'])
        ->assertUnprocessable()->assertJsonValidationErrors(['platform']);

    $this->withToken($bearer)->postJson(API_BASE.'/me/devices', ['token' => 'ExponentPushToken[ok]', 'platform' => 'android'])->assertCreated();
    $this->withToken($bearer)->deleteJson(API_BASE.'/me/devices', ['token' => 'ExponentPushToken[ok]'])->assertNoContent();

    expect(DeviceToken::count())->toBe(0);
});
