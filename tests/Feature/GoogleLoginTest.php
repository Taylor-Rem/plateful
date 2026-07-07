<?php

use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config()->set('services.google', [
        'client_id' => 'test-google-client-id',
        'client_secret' => 'test-google-client-secret',
        'redirect' => 'http://plateful.test/auth/google/callback',
    ]);
});

/**
 * Build a fake Socialite user without hitting Google.
 *
 * @param  array<string, mixed>  $attributes
 */
function fakeGoogleUser(array $attributes = []): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $attributes['id'] ?? 'google-abc';
    $user->name = $attributes['name'] ?? 'Ada Diner';
    $user->email = $attributes['email'] ?? 'ada@example.com';
    $user->avatar = $attributes['avatar'] ?? 'https://avatars.test/ada.png';
    $user->user = ['email_verified' => $attributes['email_verified'] ?? true];

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mockGoogleUser(array $attributes = []): void
{
    Socialite::shouldReceive('driver->user')->andReturn(fakeGoogleUser($attributes));
}

test('redirect route sends the customer to Google', function () {
    $response = $this->get('http://plateful.test/auth/google/redirect');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('accounts.google.com');
});

test('redirect captures the storefront origin in the session', function () {
    $response = $this->get('http://plateful.test/auth/google/redirect?return_to='.urlencode('http://marcos.plateful.test/menu'));

    $response->assertRedirect();
    expect(session('auth.google.return_host'))->toBe('marcos.plateful.test');
});

test('redirect ignores an off-platform return_to (no open redirect)', function () {
    $response = $this->get('http://plateful.test/auth/google/redirect?return_to='.urlencode('https://evil.example.com'));

    $response->assertRedirect();
    expect(session('auth.google.return_host'))->toBeNull();
});

test('callback creates a new user from a Google account and logs them in', function () {
    mockGoogleUser([
        'id' => 'google-new-1',
        'name' => 'New Bie',
        'email' => 'newbie@example.com',
        'email_verified' => true,
    ]);

    $response = $this->get('http://plateful.test/auth/google/callback');

    $response->assertRedirect(route('home'));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'newbie@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->google_id)->toBe('google-new-1');
    expect($user->name)->toBe('New Bie');
    expect($user->avatar)->toBe('https://avatars.test/ada.png');
    expect($user->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('callback logs in an existing user matched by a verified email and links google_id', function () {
    $user = User::factory()->create([
        'email' => 'existing@example.com',
        'google_id' => null,
    ]);

    mockGoogleUser([
        'id' => 'google-existing-1',
        'email' => 'existing@example.com',
        'email_verified' => true,
    ]);

    $this->get('http://plateful.test/auth/google/callback');

    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->google_id)->toBe('google-existing-1');
    expect(User::query()->where('email', 'existing@example.com')->count())->toBe(1);
});

test('callback does not auto-link when Google reports the email as unverified', function () {
    $victim = User::factory()->create([
        'email' => 'victim@example.com',
        'google_id' => null,
    ]);

    mockGoogleUser([
        'id' => 'google-attacker',
        'email' => 'victim@example.com',
        'email_verified' => false,
    ]);

    $response = $this->get('http://plateful.test/auth/google/callback');

    $this->assertGuest();
    expect($victim->fresh()->google_id)->toBeNull();
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('google=failed');
});

test('callback matches an existing user by google_id even when the email differs', function () {
    $user = User::factory()->create([
        'email' => 'known@example.com',
        'google_id' => 'google-known-id',
    ]);

    mockGoogleUser([
        'id' => 'google-known-id',
        'email' => 'a-different-email@example.com',
        'email_verified' => true,
    ]);

    $this->get('http://plateful.test/auth/google/callback');

    $this->assertAuthenticatedAs($user);
    // No new account was created for the differing email.
    expect(User::query()->where('email', 'a-different-email@example.com')->exists())->toBeFalse();
});

test('callback hands the customer back to the storefront they came from', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'handoff']);

    mockGoogleUser([
        'id' => 'google-handoff',
        'email' => 'handoff@example.com',
        'email_verified' => true,
    ]);

    $response = $this->withSession(['auth.google.return_host' => 'handoff.plateful.test'])
        ->get('http://plateful.test/auth/google/callback');

    // The platform-host callback does not establish the storefront session; it
    // hands off with a one-time token to the origin subdomain.
    $this->assertGuest();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith('http://handoff.plateful.test/auth/google/finish?token=');

    // Follow the handoff to the storefront: the token logs the customer in there
    // and lands them on their account page.
    $finish = $this->get($location);

    $this->assertAuthenticated();
    expect($finish->headers->get('Location'))->toContain('/account');

    $user = User::query()->where('email', 'handoff@example.com')->first();
    expect(RestaurantCustomer::query()
        ->where('user_id', $user->id)
        ->where('restaurant_id', $restaurant->id)
        ->exists())->toBeTrue();
});

test('the handoff token is single-use', function () {
    Restaurant::factory()->create(['subdomain' => 'handoff']);

    mockGoogleUser([
        'id' => 'google-handoff-2',
        'email' => 'handoff2@example.com',
        'email_verified' => true,
    ]);

    $response = $this->withSession(['auth.google.return_host' => 'handoff.plateful.test'])
        ->get('http://plateful.test/auth/google/callback');

    $location = $response->headers->get('Location');

    // First exchange succeeds.
    $this->get($location);
    $this->assertAuthenticated();

    // A replay of the same token is rejected and does not authenticate.
    auth()->logout();
    $replay = $this->get($location);
    $this->assertGuest();
    $replay->assertRedirect(route('login'));
});

test('a denied consent redirects back to login without a 500', function () {
    $response = $this->withSession(['auth.google.return_host' => 'marcos.plateful.test'])
        ->get('http://plateful.test/auth/google/callback?error=access_denied');

    $this->assertGuest();
    $response->assertRedirect('http://marcos.plateful.test/login?google=failed');
});

test('the Google button is hidden on the login page when Google is not configured', function () {
    config()->set('services.google.client_id', null);
    Restaurant::factory()->create(['subdomain' => 'nogoog']);

    $response = $this->get('http://nogoog.plateful.test/login');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/Login')
        ->where('googleEnabled', false));
});

test('the Google button is shown on the login page when Google is configured', function () {
    Restaurant::factory()->create(['subdomain' => 'goog']);

    $response = $this->get('http://goog.plateful.test/login');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/Login')
        ->where('googleEnabled', true));
});
