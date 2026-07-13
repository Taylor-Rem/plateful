<?php

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\StorefrontLoginHandoff;
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
        'password' => 'super-secret-password',
        'restaurant_name' => "Marco's Pizza",
        'subdomain' => 'marcos-pizza',
        'timezone' => 'America/Chicago',
    ], $overrides);
}

it('renders the owner marketing landing page on the root domain', function () {
    $this->get(ROOT.'/for-restaurants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ForRestaurants/Landing')
            ->where('authUserName', null)
            ->where('hasAdminAccess', false)
            ->where('adminUrl', 'http://admin.'.config('platform.primary_domain')));
});

it('tells the owner landing page when a signed-in user has admin access', function () {
    $owner = User::factory()->create(['name' => 'Marco']);
    Restaurant::factory()->create()->members()->attach($owner->id, [
        'role' => RestaurantRole::Admin->value,
    ]);

    $this->actingAs($owner)
        ->get(ROOT.'/for-restaurants')
        ->assertInertia(fn ($page) => $page
            ->where('authUserName', 'Marco')
            ->where('hasAdminAccess', true));
});

it('greets a signed-in diner on the owner landing page without admin access', function () {
    $diner = User::factory()->create(['name' => 'Dana']);

    $this->actingAs($diner)
        ->get(ROOT.'/for-restaurants')
        ->assertInertia(fn ($page) => $page
            ->where('authUserName', 'Dana')
            ->where('hasAdminAccess', false));
});

it('renders the signup form', function () {
    $this->get(ROOT.'/for-restaurants/signup')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ForRestaurants/Signup')
            ->where('primaryDomain', config('platform.primary_domain')));
});

it('self-serve signup creates the user and restaurant, grants admin, and hands the login to the admin host', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertRedirectContains('http://admin.plateful.test/auth/handoff');

    $user = User::where('email', 'marco@example.com')->first();
    expect($user)->not->toBeNull();

    $restaurant = Restaurant::where('subdomain', 'marcos-pizza')->first();
    expect($restaurant)->not->toBeNull()
        ->and($restaurant->name)->toBe("Marco's Pizza")
        ->and($restaurant->status)->toBe(RestaurantStatus::Approved)
        ->and($restaurant->is_active)->toBeTrue()
        ->and($restaurant->timezone)->toBe('America/Chicago')
        ->and($restaurant->isLive())->toBeFalse()
        ->and($restaurant->menuItems()->exists())->toBeFalse();

    // Owner gains admin access via the pivot the moment they sign up.
    expect($user->fresh()->isRestaurantAdminAt($restaurant))->toBeTrue();

    expect(Auth::id())->toBe($user->id);
});

it('the handoff URL from signup establishes a session on the admin host and lands on onboarding', function () {
    $response = $this->post(ROOT.'/for-restaurants/signup', signupPayload());

    $user = User::where('email', 'marco@example.com')->first();

    // Sessions are host-scoped in real browsers: the primary-host login does
    // not carry over. Simulate arriving on the admin host unauthenticated.
    Auth::logout();

    $this->get($response->headers->get('Location'))
        ->assertRedirect('http://admin.plateful.test/marcos-pizza/onboarding');

    expect(Auth::id())->toBe($user->id);
});

it('rejects an expired or invalid handoff token', function () {
    $this->get('http://admin.plateful.test/auth/handoff?token=garbage&to=/x/onboarding')
        ->assertRedirect('http://admin.plateful.test');

    expect(Auth::guest())->toBeTrue();
});

it('never redirects the handoff to another origin', function () {
    $user = User::factory()->create();
    $token = app(StorefrontLoginHandoff::class)->issue($user, 'admin.plateful.test');

    $this->get('http://admin.plateful.test/auth/handoff?'.http_build_query([
        'token' => $token,
        'to' => '//evil.example.com/phish',
    ]))->assertRedirect('http://admin.plateful.test');
});

it('signup does not require a password confirmation field', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload())
        ->assertSessionHasNoErrors();
});

it('defaults the timezone when the browser does not provide one', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload(['timezone' => null]));

    expect(Restaurant::where('subdomain', 'marcos-pizza')->first()->timezone)
        ->toBe('America/New_York');
});

it('rejects an invalid timezone', function () {
    $this->post(ROOT.'/for-restaurants/signup', signupPayload(['timezone' => 'Mars/Olympus_Mons']))
        ->assertSessionHasErrors('timezone');

    expect(Restaurant::query()->count())->toBe(0);
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

it('answers precognitive subdomain checks without creating anything', function () {
    Restaurant::factory()->create(['subdomain' => 'marcos-pizza']);

    // Taken subdomain → validation error, nothing persisted.
    $this->withHeaders([
        'Precognition' => 'true',
        'Precognition-Validate-Only' => 'subdomain',
    ])->postJson(ROOT.'/for-restaurants/signup', ['subdomain' => 'marcos-pizza'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('subdomain');

    // Free subdomain → success, still nothing persisted.
    $this->withHeaders([
        'Precognition' => 'true',
        'Precognition-Validate-Only' => 'subdomain',
    ])->postJson(ROOT.'/for-restaurants/signup', ['subdomain' => 'free-and-clear'])
        ->assertSuccessful();

    expect(User::query()->count())->toBe(0)
        ->and(Restaurant::query()->count())->toBe(1);
});
