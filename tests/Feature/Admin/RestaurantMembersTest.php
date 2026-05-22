<?php

use App\Enums\RestaurantRole;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const MEMBERS_ADMIN_BASE = 'http://admin.plateful.test';

function membersRestaurant(string $sub = 'membertest'): Restaurant
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

function attachMember(Restaurant $r, string $role = 'admin'): User
{
    $user = User::factory()->admin()->create();
    $user->restaurants()->attach($r->id, ['role' => $role]);

    return $user;
}

test('restaurant admin can view the members page', function () {
    $r = membersRestaurant();
    $admin = attachMember($r, 'admin');

    $this->actingAs($admin)
        ->get(MEMBERS_ADMIN_BASE."/{$r->subdomain}/members")
        ->assertOk();
});

test('staff cannot access the members page', function () {
    $r = membersRestaurant();
    $staff = attachMember($r, 'staff');

    $this->actingAs($staff)
        ->get(MEMBERS_ADMIN_BASE."/{$r->subdomain}/members")
        ->assertForbidden();
});

test('staff cannot create menu categories', function () {
    $r = membersRestaurant();
    $staff = attachMember($r, 'staff');

    $this->actingAs($staff)
        ->post(MEMBERS_ADMIN_BASE."/{$r->subdomain}/menu/categories", [
            'name' => 'New cat',
        ])
        ->assertForbidden();
});

test('staff cannot access settings', function () {
    $r = membersRestaurant();
    $staff = attachMember($r, 'staff');

    $this->actingAs($staff)
        ->get(MEMBERS_ADMIN_BASE."/{$r->subdomain}/settings")
        ->assertForbidden();
});

test('staff can view orders', function () {
    $r = membersRestaurant();
    $staff = attachMember($r, 'staff');

    $this->actingAs($staff)
        ->get(MEMBERS_ADMIN_BASE."/{$r->subdomain}/orders")
        ->assertOk();
});

test('staff can view the menu (read only)', function () {
    $r = membersRestaurant();
    $staff = attachMember($r, 'staff');

    $this->actingAs($staff)
        ->get(MEMBERS_ADMIN_BASE."/{$r->subdomain}/menu")
        ->assertOk();
});

test('staff can manage hours', function () {
    $r = membersRestaurant();
    $staff = attachMember($r, 'staff');

    $this->actingAs($staff)
        ->get(MEMBERS_ADMIN_BASE."/{$r->subdomain}/hours")
        ->assertOk();
});

test('admin can invite a staff member', function () {
    Mail::fake();

    $r = membersRestaurant();
    $admin = attachMember($r, 'admin');

    $this->actingAs($admin)
        ->post(MEMBERS_ADMIN_BASE."/{$r->subdomain}/invitations", [
            'email' => 'newstaff@example.com',
            'role' => 'staff',
        ])
        ->assertRedirect();

    $invitation = AdminInvitation::query()->where('email', 'newstaff@example.com')->first();

    expect($invitation)->not->toBeNull();
    expect($invitation->role)->toBe(RestaurantRole::Staff);
});

test('accepting a staff invitation attaches user with staff role', function () {
    $r = membersRestaurant();
    $invitation = AdminInvitation::create([
        'email' => 'staffinvite@example.com',
        'restaurant_id' => $r->id,
        'role' => 'staff',
        'as_super_admin' => false,
        'token' => AdminInvitation::generateToken(),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(MEMBERS_ADMIN_BASE."/invitations/{$invitation->token}", [
        'name' => 'Staff Person',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect();

    $user = User::query()->where('email', 'staffinvite@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->roleAt($r))->toBe(RestaurantRole::Staff);
});

test('admin can change a member role', function () {
    $r = membersRestaurant();
    $admin = attachMember($r, 'admin');
    $other = attachMember($r, 'admin');

    $this->actingAs($admin)
        ->put(MEMBERS_ADMIN_BASE."/{$r->subdomain}/members/{$other->id}", [
            'role' => 'staff',
        ])
        ->assertRedirect();

    expect($other->fresh()->roleAt($r))->toBe(RestaurantRole::Staff);
});

test('admin cannot demote themselves', function () {
    $r = membersRestaurant();
    $admin = attachMember($r, 'admin');

    $this->actingAs($admin)
        ->put(MEMBERS_ADMIN_BASE."/{$r->subdomain}/members/{$admin->id}", [
            'role' => 'staff',
        ])
        ->assertSessionHasErrors('role');

    expect($admin->fresh()->roleAt($r))->toBe(RestaurantRole::Admin);
});

test('admin can remove a member', function () {
    $r = membersRestaurant();
    $admin = attachMember($r, 'admin');
    $other = attachMember($r, 'staff');

    $this->actingAs($admin)
        ->delete(MEMBERS_ADMIN_BASE."/{$r->subdomain}/members/{$other->id}")
        ->assertRedirect();

    expect($r->members()->where('users.id', $other->id)->exists())->toBeFalse();
});

test('admin cannot remove themselves', function () {
    $r = membersRestaurant();
    $admin = attachMember($r, 'admin');

    $this->actingAs($admin)
        ->delete(MEMBERS_ADMIN_BASE."/{$r->subdomain}/members/{$admin->id}")
        ->assertSessionHasErrors('member');

    expect($r->members()->where('users.id', $admin->id)->exists())->toBeTrue();
});

test('admin can revoke a pending invitation', function () {
    $r = membersRestaurant();
    $admin = attachMember($r, 'admin');
    $invitation = AdminInvitation::create([
        'email' => 'pending@example.com',
        'restaurant_id' => $r->id,
        'role' => 'staff',
        'as_super_admin' => false,
        'token' => AdminInvitation::generateToken(),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($admin)
        ->delete(MEMBERS_ADMIN_BASE."/{$r->subdomain}/invitations/{$invitation->id}")
        ->assertRedirect();

    expect(AdminInvitation::find($invitation->id))->toBeNull();
});
