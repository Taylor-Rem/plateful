<?php

use App\Enums\UserRole;
use App\Models\User;

it('creates a super admin with the right fields', function () {
    $this->artisan('plateful:create-super-admin', [
        '--email' => 'root@example.com',
        '--name' => 'Root User',
        '--password' => 'super-secret-123!',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'root@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_super_admin)->toBeTrue()
        ->and($user->role)->toBe(UserRole::Admin)
        ->and($user->restaurant_id)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('errors out when the email already exists', function () {
    User::factory()->create(['email' => 'dup@example.com']);

    $this->artisan('plateful:create-super-admin', [
        '--email' => 'dup@example.com',
        '--name' => 'Dup',
        '--password' => 'super-secret-123!',
    ])->assertFailed();

    expect(User::query()->where('email', 'dup@example.com')->count())->toBe(1);
});

it('rejects short passwords', function () {
    $this->artisan('plateful:create-super-admin', [
        '--email' => 'short@example.com',
        '--name' => 'Short',
        '--password' => 'tooshort',
    ])->assertExitCode(2);

    expect(User::query()->where('email', 'short@example.com')->exists())->toBeFalse();
});
