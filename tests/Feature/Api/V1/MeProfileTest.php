<?php

use App\Models\User;

require_once __DIR__.'/ApiHelpers.php';

test('profile fields update partially and a new email is un-verified', function () {
    $user = User::factory()->create(['name' => 'Ada', 'phone' => null]);
    $bearer = apiTokenFor($user);

    $this->withToken($bearer)->patchJson(API_BASE.'/me', ['phone' => '801-555-0100'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada')
        ->assertJsonPath('data.phone', '801-555-0100')
        ->assertJsonPath('data.emailVerified', true)
        ->assertJsonPath('data.pushOrderUpdates', true);

    $this->withToken($bearer)->patchJson(API_BASE.'/me', ['email' => 'new@example.com', 'push_order_updates' => false])
        ->assertOk()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.emailVerified', false)
        ->assertJsonPath('data.pushOrderUpdates', false);

    expect($user->fresh()->push_order_updates)->toBeFalse();
});

test('an email already on another live account is rejected', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->withToken(apiTokenFor($user))->patchJson(API_BASE.'/me', ['email' => 'taken@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
});
