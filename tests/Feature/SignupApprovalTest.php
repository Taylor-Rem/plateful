<?php

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Mail\RestaurantSignupApprovedMail;
use App\Mail\RestaurantSignupRejectedMail;
use App\Models\Restaurant;
use App\Models\RestaurantSignup;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const ADMIN = 'http://admin.plateful.test';

beforeEach(function () {
    Mail::fake();
    $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
});

it('lists pending signups for super admins', function () {
    RestaurantSignup::factory()->count(2)->create();
    RestaurantSignup::factory()->approved()->create();
    RestaurantSignup::factory()->rejected()->create();

    $this->actingAs($this->superAdmin)
        ->get(ADMIN.'/super/signups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/SuperAdmin/Signups/Index')
            ->where('counts.pending', 2)
            ->where('counts.approved', 1)
            ->where('counts.rejected', 1)
            ->has('signups', 2));
});

it('filters the signup list by status', function () {
    RestaurantSignup::factory()->create();
    RestaurantSignup::factory()->rejected()->create();

    $this->actingAs($this->superAdmin)
        ->get(ADMIN.'/super/signups?status=rejected')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('status', 'rejected')
            ->has('signups', 1));
});

it('blocks non-super-admin from the signup list', function () {
    $restaurant = Restaurant::factory()->create();
    $admin = User::factory()->create();
    $restaurant->members()->attach($admin->id, ['role' => RestaurantRole::Admin->value]);

    $this->actingAs($admin)
        ->get(ADMIN.'/super/signups')
        ->assertForbidden();
});

it('approving a signup creates the restaurant and attaches the owner as admin', function () {
    $owner = User::factory()->create();
    $signup = RestaurantSignup::factory()->for($owner)->create([
        'proposed_name' => "Marco's Pizza",
        'proposed_subdomain' => 'marcos-pizza',
        'city' => 'Brooklyn',
        'state' => 'NY',
    ]);

    $this->actingAs($this->superAdmin)
        ->post(ADMIN."/super/signups/{$signup->id}/approve")
        ->assertRedirect(ADMIN."/super/signups/{$signup->id}");

    $restaurant = Restaurant::where('subdomain', 'marcos-pizza')->first();
    expect($restaurant)->not->toBeNull()
        ->and($restaurant->status)->toBe(RestaurantStatus::Approved)
        ->and($restaurant->isLive())->toBeFalse()
        ->and($restaurant->approved_by_user_id)->toBe($this->superAdmin->id);

    $signup->refresh();
    expect($signup->status)->toBe(RestaurantSignup::STATUS_APPROVED)
        ->and($signup->restaurant_id)->toBe($restaurant->id)
        ->and($signup->reviewed_by_user_id)->toBe($this->superAdmin->id);

    expect($owner->fresh()->isRestaurantAdminAt($restaurant))->toBeTrue();

    Mail::assertQueued(
        RestaurantSignupApprovedMail::class,
        fn (RestaurantSignupApprovedMail $mail) => $mail->hasTo($owner->email)
    );
});

it('approved restaurant does NOT appear on the public homepage until owner goes live', function () {
    $signup = RestaurantSignup::factory()->create();

    $this->actingAs($this->superAdmin)
        ->post(ADMIN."/super/signups/{$signup->id}/approve");

    expect(Restaurant::query()->public()->count())->toBe(0);
});

it('refuses to approve when the subdomain has been claimed since submission', function () {
    $signup = RestaurantSignup::factory()->create(['proposed_subdomain' => 'marcos-pizza']);
    Restaurant::factory()->create(['subdomain' => 'marcos-pizza']);

    $this->actingAs($this->superAdmin)
        ->from(ADMIN."/super/signups/{$signup->id}")
        ->post(ADMIN."/super/signups/{$signup->id}/approve")
        ->assertSessionHasErrors('proposed_subdomain');

    expect($signup->fresh()->status)->toBe(RestaurantSignup::STATUS_PENDING);
    Mail::assertNothingQueued();
});

it('refuses to re-review a signup that is already approved', function () {
    $signup = RestaurantSignup::factory()->approved()->create();

    $this->actingAs($this->superAdmin)
        ->post(ADMIN."/super/signups/{$signup->id}/approve")
        ->assertRedirect();

    Mail::assertNothingQueued();
});

it('rejecting a signup records the reason and emails the owner', function () {
    $owner = User::factory()->create();
    $signup = RestaurantSignup::factory()->for($owner)->create();

    $this->actingAs($this->superAdmin)
        ->post(ADMIN."/super/signups/{$signup->id}/reject", [
            'rejection_reason' => 'Need more documentation.',
        ])
        ->assertRedirect(ADMIN."/super/signups/{$signup->id}");

    $signup->refresh();
    expect($signup->status)->toBe(RestaurantSignup::STATUS_REJECTED)
        ->and($signup->rejection_reason)->toBe('Need more documentation.')
        ->and($signup->reviewed_by_user_id)->toBe($this->superAdmin->id);

    // Owner is NOT an admin of anything.
    expect($owner->fresh()->isAdmin())->toBeFalse();

    Mail::assertQueued(
        RestaurantSignupRejectedMail::class,
        fn (RestaurantSignupRejectedMail $mail) => $mail->hasTo($owner->email)
    );
});

it('rejection requires a reason', function () {
    $signup = RestaurantSignup::factory()->create();

    $this->actingAs($this->superAdmin)
        ->post(ADMIN."/super/signups/{$signup->id}/reject", [])
        ->assertSessionHasErrors('rejection_reason');
});

it('admin home shows the pending signups count for super admins', function () {
    RestaurantSignup::factory()->count(3)->create();
    RestaurantSignup::factory()->approved()->create();

    $this->actingAs($this->superAdmin)
        ->get(ADMIN)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('pendingSignupsCount', 3));
});
