<?php

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;

const SUPER_ADMIN_MGMT_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('super admin can edit an admin name and email', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->admin()->create(['name' => 'Old Name', 'email' => 'old@example.test']);

    $response = $this->actingAs($superAdmin)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$target->id}", [
            'name' => 'New Name',
            'email' => 'new@example.test',
        ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $fresh = $target->fresh();
    expect($fresh->name)->toBe('New Name');
    expect($fresh->email)->toBe('new@example.test');
});

test('changing an admin email clears their verified status', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->admin()->create([
        'email' => 'old@example.test',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($superAdmin)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$target->id}", [
            'name' => $target->name,
            'email' => 'new@example.test',
        ]);

    expect($target->fresh()->email_verified_at)->toBeNull();
});

test('editing an admin without changing the email keeps them verified', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->admin()->create([
        'email' => 'stable@example.test',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($superAdmin)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$target->id}", [
            'name' => 'Renamed',
            'email' => 'stable@example.test',
        ]);

    expect($target->fresh()->email_verified_at)->not->toBeNull();
});

test('an admin email must be unique', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    User::factory()->create(['email' => 'taken@example.test']);
    $target = User::factory()->admin()->create(['email' => 'target@example.test']);

    $this->actingAs($superAdmin)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$target->id}", [
            'name' => $target->name,
            'email' => 'taken@example.test',
        ])
        ->assertSessionHasErrors('email');

    expect($target->fresh()->email)->toBe('target@example.test');
});

test('super admin can grant super-admin status', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->admin()->create();

    $this->actingAs($superAdmin)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$target->id}/super-admin", [
            'is_super_admin' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->is_super_admin)->toBeTrue();
});

test('super admin can revoke super-admin status from another super admin', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$target->id}/super-admin", [
            'is_super_admin' => false,
        ]);

    expect($target->fresh()->is_super_admin)->toBeFalse();
});

test('you cannot revoke your own super-admin status', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$superAdmin->id}/super-admin", [
            'is_super_admin' => false,
        ])
        ->assertSessionHas('error');

    expect($superAdmin->fresh()->is_super_admin)->toBeTrue();
});

test('the sole super admin cannot be demoted (self-lockout is blocked)', function () {
    // With exactly one super admin in the system, the only person who could
    // issue this request is that super admin acting on themselves — which the
    // self guard refuses, keeping at least one super admin on the platform.
    $onlySuper = User::factory()->superAdmin()->create();

    expect(User::where('is_super_admin', true)->count())->toBe(1);

    $this->actingAs($onlySuper)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$onlySuper->id}/super-admin", [
            'is_super_admin' => false,
        ])
        ->assertSessionHas('error');

    expect($onlySuper->fresh()->is_super_admin)->toBeTrue();
});

test('super admin can remove all admin access from a person', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create();
    $target = User::factory()->create();
    $target->restaurants()->attach($restaurant, ['role' => RestaurantRole::Admin->value]);

    $this->actingAs($superAdmin)
        ->delete(SUPER_ADMIN_MGMT_BASE."/super/admins/{$target->id}")
        ->assertSessionHasNoErrors();

    $fresh = $target->fresh();
    expect($fresh->is_super_admin)->toBeFalse();
    expect($fresh->restaurants()->count())->toBe(0);
    // The user record itself is kept.
    expect(User::find($target->id))->not->toBeNull();
});

test('you cannot remove your own admin access', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->delete(SUPER_ADMIN_MGMT_BASE."/super/admins/{$superAdmin->id}")
        ->assertSessionHas('error');

    expect($superAdmin->fresh()->is_super_admin)->toBeTrue();
});

test('a tenant admin cannot manage admins', function () {
    $restaurant = Restaurant::factory()->create();
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant, ['role' => RestaurantRole::Admin->value]);
    $target = User::factory()->admin()->create(['name' => 'Untouchable']);

    $this->actingAs($admin)
        ->put(SUPER_ADMIN_MGMT_BASE."/super/admins/{$target->id}", [
            'name' => 'Hacked',
            'email' => 'hacked@example.test',
        ])
        ->assertForbidden();

    expect($target->fresh()->name)->toBe('Untouchable');
});
