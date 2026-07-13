<?php

use App\Enums\RevenueRole;
use App\Models\FeeDistribution;
use App\Models\Order;
use App\Models\PlatformRoleHolder;
use App\Models\Restaurant;
use App\Models\User;

const SUPER_ROLES_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['platform.revenue_shares' => ['founder' => 10, 'recruiter' => 0, 'overseer' => 90]]);
});

test('a super admin can assign a restaurant overseer and recruiter', function () {
    $super = User::factory()->superAdmin()->create();
    $ben = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);

    $response = $this->actingAs($super)
        ->put(SUPER_ROLES_BASE."/super/restaurants/{$restaurant->subdomain}/roles", [
            'overseer_id' => $ben->id,
            'recruiter_id' => $ben->id,
        ]);

    $response->assertRedirect();
    $restaurant->refresh();
    expect($restaurant->overseer_id)->toBe($ben->id);
    expect($restaurant->recruiter_id)->toBe($ben->id);
});

test('assigning empty roles clears them', function () {
    $super = User::factory()->superAdmin()->create();
    $ben = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'marcos',
        'overseer_id' => $ben->id,
    ]);

    $this->actingAs($super)
        ->put(SUPER_ROLES_BASE."/super/restaurants/{$restaurant->subdomain}/roles", [
            'overseer_id' => null,
            'recruiter_id' => null,
        ])->assertRedirect();

    expect($restaurant->fresh()->overseer_id)->toBeNull();
});

test('a tenant admin cannot assign revenue roles', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant, ['role' => 'admin']);

    $this->actingAs($admin)
        ->put(SUPER_ROLES_BASE."/super/restaurants/{$restaurant->subdomain}/roles", [
            'overseer_id' => $admin->id,
        ])->assertForbidden();
});

test('a super admin can set the platform founder and operator', function () {
    $super = User::factory()->superAdmin()->create();
    $taylor = User::factory()->superAdmin()->create();

    $this->actingAs($super)
        ->put(SUPER_ROLES_BASE.'/super/platform-roles', [
            'founder_id' => $taylor->id,
            'operator_id' => $taylor->id,
        ])->assertRedirect();

    expect(PlatformRoleHolder::holder(RevenueRole::Founder)?->id)->toBe($taylor->id);
    expect(PlatformRoleHolder::holder(RevenueRole::Operator)?->id)->toBe($taylor->id);
});

test('the earnings report aggregates the ledger per person for the month', function () {
    $super = User::factory()->superAdmin()->create();
    $taylor = User::factory()->create(['name' => 'Taylor']);
    $ben = User::factory()->create(['name' => 'Ben']);
    $restaurant = Restaurant::factory()->create();

    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'placed_at' => '2026-06-15 12:00:00',
    ]);

    FeeDistribution::factory()->create([
        'order_id' => $order->id, 'restaurant_id' => $restaurant->id, 'user_id' => $taylor->id,
        'role' => RevenueRole::Founder, 'percent' => 10, 'amount_cents' => 100,
        'earned_at' => '2026-06-15 12:00:00',
    ]);
    FeeDistribution::factory()->create([
        'order_id' => $order->id, 'restaurant_id' => $restaurant->id, 'user_id' => $ben->id,
        'role' => RevenueRole::Overseer, 'percent' => 90, 'amount_cents' => 900,
        'earned_at' => '2026-06-15 12:00:00',
    ]);

    $this->actingAs($super)
        ->get(SUPER_ROLES_BASE.'/super/earnings?month=2026-06')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/SuperAdmin/Earnings')
            ->where('totalCents', 1000)
            ->has('earners', 2)
            // Sorted by total desc: Ben (900) first.
            ->where('earners.0.name', 'Ben')
            ->where('earners.0.totalCents', 900)
            ->where('earners.1.totalCents', 100)
        );
});

test('the earnings report excludes fully-refunded orders', function () {
    $super = User::factory()->superAdmin()->create();
    $ben = User::factory()->create();
    $restaurant = Restaurant::factory()->create();

    $refunded = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'placed_at' => '2026-06-10 12:00:00',
        'refunded_at' => '2026-06-11 09:00:00',
    ]);

    FeeDistribution::factory()->create([
        'order_id' => $refunded->id, 'restaurant_id' => $restaurant->id, 'user_id' => $ben->id,
        'role' => RevenueRole::Overseer, 'percent' => 90, 'amount_cents' => 900,
        'earned_at' => '2026-06-10 12:00:00',
    ]);

    $this->actingAs($super)
        ->get(SUPER_ROLES_BASE.'/super/earnings?month=2026-06')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totalCents', 0)->has('earners', 0));
});
