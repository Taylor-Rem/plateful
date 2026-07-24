<?php

use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const PLATFORM_INVITE_BASE = 'http://admin.plateful.test';

function platformInvitation(array $overrides = []): AdminInvitation
{
    return AdminInvitation::create(array_merge([
        'email' => 'pending@example.com',
        'restaurant_id' => null,
        'as_super_admin' => false,
        'token' => AdminInvitation::generateToken(),
        'expires_at' => now()->addDays(7),
    ], $overrides));
}

test('storing a platform invitation queues the mail and flashes success', function () {
    Mail::fake();

    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)
        ->from(PLATFORM_INVITE_BASE.'/super/admins')
        ->post(PLATFORM_INVITE_BASE.'/super/admins/invitations', [
            'email' => 'new@example.com',
            'as_super_admin' => true,
        ]);

    $response->assertRedirect(PLATFORM_INVITE_BASE.'/super/admins');
    $response->assertSessionHas('success', 'Invitation sent to new@example.com.');

    Mail::assertQueued(AdminInvitationMail::class);

    $invitation = AdminInvitation::query()->where('email', 'new@example.com')->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->as_super_admin)->toBeTrue()
        ->and($invitation->invited_by_user_id)->toBe($superAdmin->id);
});

test('non-super users cannot store platform invitations', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(PLATFORM_INVITE_BASE.'/super/admins/invitations', [
            'email' => 'new@example.com',
        ]);

    $response->assertForbidden();
});

test('a pending invitation can be revoked', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $invitation = platformInvitation();

    $response = $this->actingAs($superAdmin)
        ->from(PLATFORM_INVITE_BASE.'/super/admins')
        ->delete(PLATFORM_INVITE_BASE."/super/admins/invitations/{$invitation->id}");

    $response->assertRedirect(PLATFORM_INVITE_BASE.'/super/admins');
    $response->assertSessionHas('success', 'Invitation revoked.');
    expect(AdminInvitation::query()->find($invitation->id))->toBeNull();
});

test('revoking an already-accepted invitation returns 404', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $accepted = User::factory()->create();
    $invitation = platformInvitation([
        'accepted_at' => now(),
        'accepted_user_id' => $accepted->id,
    ]);

    $response = $this->actingAs($superAdmin)
        ->delete(PLATFORM_INVITE_BASE."/super/admins/invitations/{$invitation->id}");

    $response->assertNotFound();
    expect(AdminInvitation::query()->find($invitation->id))->not->toBeNull();
});

test('the admins index lists pending invitations', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    platformInvitation(['email' => 'listed@example.com']);
    platformInvitation([
        'email' => 'gone@example.com',
        'accepted_at' => now(),
        'accepted_user_id' => User::factory()->create()->id,
    ]);

    $response = $this->actingAs($superAdmin)->get(PLATFORM_INVITE_BASE.'/super/admins');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/SuperAdmin/Admins')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.email', 'listed@example.com'));
});
