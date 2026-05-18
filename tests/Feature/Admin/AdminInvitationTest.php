<?php

use App\Enums\UserRole;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const INVITE_ADMIN_BASE = 'http://admin.plateful.test';

function inviteRestaurant(string $sub = 'invitee'): Restaurant
{
    return Restaurant::create([
        'name' => "R-{$sub}",
        'subdomain' => $sub,
        'email' => "hello@{$sub}.test",
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('super admin can create an invitation', function () {
    Mail::fake();

    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = inviteRestaurant();

    $response = $this->actingAs($superAdmin)
        ->post(INVITE_ADMIN_BASE.'/super/admins/invitations', [
            'email' => 'new@example.com',
            'restaurant_id' => $restaurant->id,
            'as_super_admin' => false,
        ]);

    $response->assertRedirect();
    expect(AdminInvitation::query()->where('email', 'new@example.com')->exists())->toBeTrue();
});

test('accepting a valid invitation creates a user with pivot and logs them in', function () {
    $restaurant = inviteRestaurant();
    $invitation = AdminInvitation::create([
        'email' => 'new@example.com',
        'restaurant_id' => $restaurant->id,
        'as_super_admin' => false,
        'token' => AdminInvitation::generateToken(),
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->post(INVITE_ADMIN_BASE."/invitations/{$invitation->token}", [
        'name' => 'New Admin',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect('/');

    $user = User::query()->where('email', 'new@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->role)->toBe(UserRole::Admin);
    expect($user->is_super_admin)->toBeFalse();
    expect($user->restaurants()->where('restaurants.id', $restaurant->id)->exists())->toBeTrue();

    $invitation->refresh();
    expect($invitation->accepted_at)->not->toBeNull();
    expect($invitation->accepted_user_id)->toBe($user->id);

    $this->assertAuthenticatedAs($user);
});

test('expired invitation cannot be used', function () {
    $invitation = AdminInvitation::create([
        'email' => 'expired@example.com',
        'restaurant_id' => null,
        'as_super_admin' => true,
        'token' => AdminInvitation::generateToken(),
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->get(INVITE_ADMIN_BASE."/invitations/{$invitation->token}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Invitations/Show')
        ->where('invitation', null));

    $postResponse = $this->post(INVITE_ADMIN_BASE."/invitations/{$invitation->token}", [
        'name' => 'Whatever',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $postResponse->assertNotFound();
});

test('already-accepted invitation redirects to login', function () {
    $invitation = AdminInvitation::create([
        'email' => 'used@example.com',
        'restaurant_id' => null,
        'as_super_admin' => true,
        'token' => AdminInvitation::generateToken(),
        'expires_at' => now()->addDays(7),
        'accepted_at' => now(),
    ]);

    $response = $this->get(INVITE_ADMIN_BASE."/invitations/{$invitation->token}");

    $response->assertRedirect();
});
