<?php

use App\Mail\RestaurantSignupSubmittedMail;
use App\Models\Restaurant;
use App\Models\RestaurantSignup;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

const ROOT = 'http://plateful.test';

beforeEach(function () {
    Mail::fake();
});

/**
 * Minimal valid signup payload — individual tests can override fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Marco Polo',
        'email' => 'marco@example.com',
        'phone' => null,
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
        'restaurant_name' => "Marco's Pizza",
        'subdomain' => 'marcos-pizza',
        'custom_domain' => null,
        'cuisine_type' => 'Pizza',
        'city' => 'Brooklyn',
        'state' => 'NY',
        'notes' => 'Excited to join.',
    ], $overrides);
}

it('renders the owner marketing landing page on the root domain', function () {
    $this->get(ROOT.'/for-restaurants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ForRestaurants/Landing'));
});

it('renders the signup form with reserved subdomains', function () {
    $this->get(ROOT.'/for-restaurants/signup')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ForRestaurants/Signup')
            ->where('primaryDomain', config('platform.primary_domain'))
            ->has('reservedSubdomains'));
});

it('creates a user and a pending signup, logs in, and emails the platform', function () {
    config()->set('platform.admin_notification_email', 'platform@example.com');

    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertRedirect(ROOT.'/for-restaurants/pending');

    $user = User::where('email', 'marco@example.com')->first();
    expect($user)->not->toBeNull();

    $signup = RestaurantSignup::where('user_id', $user->id)->first();
    expect($signup)->not->toBeNull()
        ->and($signup->status)->toBe(RestaurantSignup::STATUS_PENDING)
        ->and($signup->proposed_name)->toBe("Marco's Pizza")
        ->and($signup->proposed_subdomain)->toBe('marcos-pizza')
        ->and($signup->restaurant_id)->toBeNull();

    expect(Auth::id())->toBe($user->id);

    Mail::assertQueued(
        RestaurantSignupSubmittedMail::class,
        fn (RestaurantSignupSubmittedMail $mail) => $mail->hasTo('platform@example.com')
            && $mail->signup->is($signup)
    );
});

it('does NOT make the new owner a restaurant admin until approval', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload());

    $user = User::where('email', 'marco@example.com')->first();

    expect($user->isAdmin())->toBeFalse()
        ->and($user->restaurants()->count())->toBe(0);
});

it('rejects a subdomain that already belongs to an existing restaurant', function () {
    Restaurant::factory()->create(['subdomain' => 'marcos-pizza']);

    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertSessionHasErrors('subdomain');

    expect(User::where('email', 'marco@example.com')->exists())->toBeFalse();
});

it('rejects a subdomain that is already claimed by another pending signup', function () {
    RestaurantSignup::factory()->create([
        'proposed_subdomain' => 'marcos-pizza',
        'status' => RestaurantSignup::STATUS_PENDING,
    ]);

    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertSessionHasErrors('subdomain');
});

it('allows reusing a subdomain that was previously rejected', function () {
    RestaurantSignup::factory()->rejected()->create([
        'proposed_subdomain' => 'marcos-pizza',
    ]);

    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertRedirect(ROOT.'/for-restaurants/pending');
});

it('rejects reserved subdomains', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload(['subdomain' => 'admin']))
        ->assertSessionHasErrors('subdomain');
});

it('rejects signup when the email is already in use', function () {
    User::factory()->create(['email' => 'marco@example.com']);

    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertSessionHasErrors('email');
});

it('requires password confirmation', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload([
        'password_confirmation' => 'something-else',
    ]))->assertSessionHasErrors('password');
});

it('shows the signup summary on the pending page', function () {
    $user = User::factory()->create();
    $signup = RestaurantSignup::factory()->for($user)->create([
        'proposed_name' => "Marco's Pizza",
        'proposed_subdomain' => 'marcos-pizza',
    ]);

    $this->actingAs($user)
        ->get(ROOT.'/for-restaurants/pending')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ForRestaurants/Pending')
            ->where('signup.restaurantName', $signup->proposed_name)
            ->where('signup.subdomain', $signup->proposed_subdomain));
});
