<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

require_once __DIR__.'/ApiHelpers.php';

test('me returns the signed-in account', function () {
    $user = User::factory()->create(['google_id' => 'g-1', 'phone' => '801-555-0100']);

    $this->withToken(apiTokenFor($user))->getJson(API_BASE.'/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.phone', '801-555-0100')
        ->assertJsonPath('data.emailVerified', true)
        ->assertJsonPath('data.twoFactorEnabled', false)
        ->assertJsonPath('data.linkedProviders', ['google'])
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.two_factor_secret');
});

test('me requires a bearer token', function () {
    $this->getJson(API_BASE.'/me')->assertUnauthorized();
});

test('deleting the account hard-deletes it and revokes every token', function () {
    $user = User::factory()->create();
    $phone = apiTokenFor($user, 'iPhone');
    apiTokenFor($user, 'iPad');

    $this->withToken($phone)->deleteJson(API_BASE.'/me')->assertNoContent();

    expect(User::withTrashed()->find($user->id))->toBeNull()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('the last super admin cannot delete their account from the app', function () {
    $user = User::factory()->superAdmin()->create();

    $this->withToken(apiTokenFor($user))->deleteJson(API_BASE.'/me')->assertStatus(409);

    expect(User::query()->find($user->id))->not->toBeNull();
});
