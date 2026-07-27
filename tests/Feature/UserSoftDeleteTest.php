<?php

use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * Users are soft-deleted, and email / google_id uniqueness is enforced only
 * among live accounts (partial unique indexes). Deleting an account therefore
 * frees its email and linked Google account for a brand-new signup, while the
 * deleted user can no longer authenticate.
 */

const SOFT_DELETE_ROOT = 'http://plateful.test';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ownerSignupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Marco Polo',
        'email' => 'marco@example.com',
        'password' => 'super-secret-password',
        'restaurant_name' => "Marco's Pizza",
        'subdomain' => 'marcos-pizza',
        'timezone' => 'America/Chicago',
    ], $overrides);
}

it('frees a soft-deleted user\'s email for a fresh owner signup', function () {
    $old = User::factory()->create(['email' => 'marco@example.com']);
    $old->delete();

    $this->post(SOFT_DELETE_ROOT.'/for-restaurants/signup', ownerSignupPayload())
        ->assertSessionHasNoErrors();

    // A brand-new live user now owns the address; the old row stays trashed.
    $live = User::where('email', 'marco@example.com')->first();
    expect($live)->not->toBeNull()
        ->and($live->id)->not->toBe($old->id)
        ->and(User::withTrashed()->where('email', 'marco@example.com')->count())->toBe(2);

    expect(Restaurant::where('subdomain', 'marcos-pizza')->exists())->toBeTrue();
});

it('still rejects an owner signup when a live user holds the email', function () {
    User::factory()->create(['email' => 'marco@example.com']);

    $this->post(SOFT_DELETE_ROOT.'/for-restaurants/signup', ownerSignupPayload())
        ->assertSessionHasErrors('email');

    expect(Restaurant::query()->count())->toBe(0);
});

it('prevents a soft-deleted user from authenticating with their password', function () {
    $restaurant = Restaurant::create([
        'name' => 'Auth Pizza', 'subdomain' => 'authpizza', 'email' => 'a@a.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);

    $user = User::factory()->create(['email' => 'gone@example.com']);
    RestaurantCustomer::create(['user_id' => $user->id, 'restaurant_id' => $restaurant->id]);
    $user->delete();

    $this->post("http://{$restaurant->subdomain}.plateful.test/login", [
        'email' => 'gone@example.com',
        'password' => 'password',
    ]);

    $this->assertGuest();
});

it('creates a fresh account when a soft-deleted user signs in with Google again', function () {
    config()->set('services.google', [
        'client_id' => 'test-google-client-id',
        'client_secret' => 'test-google-client-secret',
        'redirect' => 'http://plateful.test/auth/google/callback',
    ]);

    $old = User::factory()->create([
        'email' => 'ada@example.com',
        'google_id' => 'google-ada',
    ]);
    $old->delete();

    $socialite = new SocialiteUser;
    $socialite->id = 'google-ada';
    $socialite->name = 'Ada Diner';
    $socialite->email = 'ada@example.com';
    $socialite->avatar = 'https://avatars.test/ada.png';
    $socialite->user = ['email_verified' => true];
    Socialite::shouldReceive('driver->user')->andReturn($socialite);

    $this->get('http://plateful.test/auth/google/callback')->assertRedirect();

    // The trashed row is untouched; a new live account was created and logged in.
    $live = User::where('email', 'ada@example.com')->first();
    expect($live)->not->toBeNull()
        ->and($live->id)->not->toBe($old->id)
        ->and($live->google_id)->toBe('google-ada');
    expect(auth()->id())->toBe($live->id);
});
