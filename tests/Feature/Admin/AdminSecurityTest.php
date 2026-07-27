<?php

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

function securityRestaurant(string $sub = 'sec-r1'): Restaurant
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

function restaurantAdmin(): User
{
    $user = User::factory()->admin()->create();
    $user->restaurants()->attach(securityRestaurant()->id, ['role' => 'admin']);

    return $user;
}

/** A super admin who has not enrolled in two-factor authentication. */
function unenrolledSuperAdmin(): User
{
    return User::factory()->superAdmin()->create([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ]);
}

test('restaurant admin can view the admin security page', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $this->actingAs(restaurantAdmin())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Security')
            ->where('canManageTwoFactor', true)
            ->where('twoFactorEnabled', false)
            ->where('twoFactorRequired', false),
        );
});

test('admin security page requires password confirmation', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $this->actingAs(restaurantAdmin())
        ->get(route('admin.security.edit'))
        ->assertRedirect(route('password.confirm'));
});

test('admin can update their password from the admin host', function () {
    $user = restaurantAdmin();

    $this->actingAs($user)
        ->from(route('admin.security.edit'))
        ->put(route('admin.security.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.security.edit'));

    expect(Hash::check('new-secret-password', $user->refresh()->password))->toBeTrue();
});

test('super admin without two-factor is redirected to security from admin home', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $this->actingAs(unenrolledSuperAdmin())
        ->get('http://admin.plateful.test/')
        ->assertRedirect(route('admin.security.edit'));
});

test('super admin without two-factor is redirected from the super console', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $this->actingAs(unenrolledSuperAdmin())
        ->get(route('admin.super.restaurants.index'))
        ->assertRedirect(route('admin.security.edit'));
});

test('super admin without two-factor can still reach the security page', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $this->actingAs(unenrolledSuperAdmin())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Security')
            ->where('twoFactorRequired', true)
            ->where('twoFactorEnabled', false),
        );
});

test('super admin with two-factor enrolled passes the requirement', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->get(route('admin.super.restaurants.index'))
        ->assertOk();
});

test('restaurant admin without two-factor is not blocked', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $admin = User::factory()->admin()->create();
    $restaurant = securityRestaurant('nudge-r');
    $admin->restaurants()->attach($restaurant->id, ['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('admin.restaurant.dashboard', $restaurant->subdomain))
        ->assertOk();
});
