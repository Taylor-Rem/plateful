<?php

use App\Models\RestaurantCustomer;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/ApiHelpers.php';

test('registration creates a tenant-less account and signs the device in', function () {
    Event::fake([Registered::class]);

    $response = $this->postJson(API_BASE.'/auth/register', [
        'name' => 'Ada Diner',
        'email' => 'ada@example.com',
        'phone' => '801-555-0100',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'Pixel',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.name', 'Ada Diner')
        ->assertJsonPath('data.user.email', 'ada@example.com')
        ->assertJsonPath('data.user.phone', '801-555-0100')
        ->assertJsonPath('data.user.emailVerified', false)
        ->assertJsonPath('data.user.linkedProviders', []);

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect(RestaurantCustomer::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect($user->tokens()->where('name', 'Pixel')->exists())->toBeTrue();

    Event::assertDispatched(Registered::class);

    $this->withToken($response->json('data.token'))->getJson(API_BASE.'/me')->assertOk();
});

test('registration requires the password to be confirmed', function () {
    $this->postJson(API_BASE.'/auth/register', [
        'name' => 'Ada Diner',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'different',
        'device_name' => 'Pixel',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

test('registration rejects an email already on a live account', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson(API_BASE.'/auth/register', [
        'name' => 'Ada Diner',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'Pixel',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

test('registration may reuse the email of a soft-deleted account', function () {
    User::factory()->create(['email' => 'ada@example.com'])->delete();

    $this->postJson(API_BASE.'/auth/register', [
        'name' => 'Ada Again',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'Pixel',
    ])->assertOk();
});
