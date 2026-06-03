<?php

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

const ROOT = 'http://plateful.test';

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

it('self-serve signup creates the user and restaurant, grants admin, logs in, and redirects to onboarding', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertRedirect('http://admin.plateful.test/marcos-pizza/onboarding');

    $user = User::where('email', 'marco@example.com')->first();
    expect($user)->not->toBeNull();

    $restaurant = Restaurant::where('subdomain', 'marcos-pizza')->first();
    expect($restaurant)->not->toBeNull()
        ->and($restaurant->name)->toBe("Marco's Pizza")
        ->and($restaurant->status)->toBe(RestaurantStatus::Approved)
        ->and($restaurant->is_active)->toBeTrue()
        ->and($restaurant->isLive())->toBeFalse();

    // Owner gains admin access via the pivot the moment they sign up.
    expect($user->fresh()->isRestaurantAdminAt($restaurant))->toBeTrue();

    expect(Auth::id())->toBe($user->id);
});

it('a freshly signed-up restaurant is not visible on the public homepage', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload());

    expect(Restaurant::query()->public()->count())->toBe(0);
});

it('rejects a subdomain that already belongs to an existing restaurant', function () {
    Restaurant::factory()->create(['subdomain' => 'marcos-pizza']);

    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertSessionHasErrors('subdomain');

    expect(User::where('email', 'marco@example.com')->exists())->toBeFalse();
});

it('rejects reserved subdomains', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload(['subdomain' => 'admin']))
        ->assertSessionHasErrors('subdomain');

    expect(Restaurant::query()->count())->toBe(0);
});

it('rejects signup when the email is already in use', function () {
    User::factory()->create(['email' => 'marco@example.com']);

    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertSessionHasErrors('email');

    expect(Restaurant::query()->count())->toBe(0);
});

it('requires password confirmation', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload([
        'password_confirmation' => 'something-else',
    ]))->assertSessionHasErrors('password');
});
