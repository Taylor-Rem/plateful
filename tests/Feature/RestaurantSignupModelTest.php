<?php

use App\Models\Restaurant;
use App\Models\RestaurantSignup;
use App\Models\User;
use Illuminate\Database\QueryException;

it('persists a pending signup with the submitter linked', function () {
    $user = User::factory()->create();

    $signup = RestaurantSignup::factory()->for($user)->create();

    expect($signup->isPending())->toBeTrue()
        ->and($signup->isApproved())->toBeFalse()
        ->and($signup->isRejected())->toBeFalse()
        ->and($signup->user->is($user))->toBeTrue()
        ->and($signup->restaurant)->toBeNull();
});

it('links an approved signup to the created restaurant and reviewer', function () {
    $reviewer = User::factory()->create(['is_super_admin' => true]);
    $restaurant = Restaurant::factory()->approved()->create();

    $signup = RestaurantSignup::factory()
        ->approved()
        ->create([
            'restaurant_id' => $restaurant->id,
            'reviewed_by_user_id' => $reviewer->id,
        ]);

    expect($signup->isApproved())->toBeTrue()
        ->and($signup->restaurant->is($restaurant))->toBeTrue()
        ->and($signup->reviewer->is($reviewer))->toBeTrue();
});

it('enforces unique proposed_subdomain', function () {
    RestaurantSignup::factory()->create(['proposed_subdomain' => 'pizzajoint']);

    expect(fn () => RestaurantSignup::factory()->create(['proposed_subdomain' => 'pizzajoint']))
        ->toThrow(QueryException::class);
});
