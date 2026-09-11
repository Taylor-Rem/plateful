<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/ApiHelpers.php';

test('forgot-password emails a reset link to an existing account', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson(API_BASE.'/auth/forgot-password', ['email' => 'ada@example.com'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('forgot-password answers the same for an unknown email', function () {
    Notification::fake();

    $this->postJson(API_BASE.'/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonStructure(['message']);

    Notification::assertNothingSent();
});
