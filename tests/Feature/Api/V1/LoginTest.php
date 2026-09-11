<?php

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Features;

require_once __DIR__.'/ApiHelpers.php';

test('login issues a device token and returns the account', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->postJson(API_BASE.'/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'password',
        'device_name' => 'iPhone',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'ada@example.com')
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'phone', 'avatar', 'emailVerified', 'twoFactorEnabled', 'linkedProviders', 'createdAt']]]);

    $token = $response->json('data.token');

    $this->withToken($token)->getJson(API_BASE.'/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    expect($user->tokens()->where('name', 'iPhone')->count())->toBe(1);
});

test('login rejects a wrong password with a validation error', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson(API_BASE.'/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'nope',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

test('login rejects an unknown email with the same error as a wrong password', function () {
    $this->postJson(API_BASE.'/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'password',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

test('login requires a device name', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson(API_BASE.'/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'password',
    ])->assertUnprocessable()->assertJsonValidationErrors(['device_name']);
});

test('signing in again on the same device replaces its token', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $first = $this->postJson(API_BASE.'/auth/login', [
        'email' => 'ada@example.com', 'password' => 'password', 'device_name' => 'iPhone',
    ])->json('data.token');

    $this->postJson(API_BASE.'/auth/login', [
        'email' => 'ada@example.com', 'password' => 'password', 'device_name' => 'iPhone',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(1);
    forgetApiGuards();

    $this->withToken($first)->getJson(API_BASE.'/me')->assertUnauthorized();
});

test('a soft-deleted account cannot sign in', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->delete();

    $this->postJson(API_BASE.'/auth/login', [
        'email' => 'ada@example.com', 'password' => 'password', 'device_name' => 'iPhone',
    ])->assertUnprocessable();
});

test('logout revokes only the current device token', function () {
    $user = User::factory()->create();
    $phone = apiTokenFor($user, 'iPhone');
    $tablet = apiTokenFor($user, 'iPad');

    $this->withToken($phone)->deleteJson(API_BASE.'/auth/logout')->assertNoContent();
    forgetApiGuards();

    $this->withToken($phone)->getJson(API_BASE.'/me')->assertUnauthorized();
    forgetApiGuards();
    $this->withToken($tablet)->getJson(API_BASE.'/me')->assertOk();
});

test('unauthenticated requests get a JSON 401 without an Accept header', function () {
    $this->get(API_BASE.'/me')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('message', 'Unauthenticated.');
});

describe('two-factor', function () {
    beforeEach(function () {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    });

    function challengeFor(User $user): string
    {
        return test()->postJson(API_BASE.'/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_name' => 'iPhone',
        ])->assertStatus(202)
            ->assertJsonPath('data.twoFactorRequired', true)
            ->json('data.challengeToken');
    }

    test('an enrolled account gets a challenge token instead of a session', function () {
        $user = User::factory()->withTwoFactor()->create();

        $challenge = challengeFor($user);

        expect($user->tokens()->count())->toBe(1);
        expect($user->tokens()->first()->abilities)->toBe(['two-factor-challenge']);

        // The challenge token cannot be used as a customer token.
        forgetApiGuards();
        $this->withToken($challenge)->getJson(API_BASE.'/me')->assertForbidden();
    });

    test('a valid TOTP code swaps the challenge for a device token', function () {
        $user = User::factory()->withTwoFactor()->create();
        $challenge = challengeFor($user);

        $this->mock(TwoFactorAuthenticationProvider::class)
            ->shouldReceive('verify')->once()->with('secret', '123456')->andReturn(true);

        $response = $this->withToken($challenge)->postJson(API_BASE.'/auth/two-factor', ['code' => '123456']);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);

        expect($user->tokens()->pluck('name')->all())->toBe(['iPhone']);
        forgetApiGuards();
        $this->withToken($challenge)->getJson(API_BASE.'/me')->assertUnauthorized();
        forgetApiGuards();
        $this->withToken($response->json('data.token'))->getJson(API_BASE.'/me')->assertOk();
    });

    test('an invalid TOTP code is rejected and the challenge stays open', function () {
        $user = User::factory()->withTwoFactor()->create();
        $challenge = challengeFor($user);

        $this->mock(TwoFactorAuthenticationProvider::class)
            ->shouldReceive('verify')->once()->andReturn(false);

        $this->withToken($challenge)->postJson(API_BASE.'/auth/two-factor', ['code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);

        expect($user->tokens()->first()->abilities)->toBe(['two-factor-challenge']);
    });

    test('a recovery code completes the challenge and is consumed', function () {
        $user = User::factory()->withTwoFactor()->create();
        $challenge = challengeFor($user);

        $this->withToken($challenge)->postJson(API_BASE.'/auth/two-factor', ['recovery_code' => 'recovery-code-1'])
            ->assertOk();

        expect($user->fresh()->recoveryCodes())->not->toContain('recovery-code-1');
    });

    test('the challenge requires a code or a recovery code', function () {
        $user = User::factory()->withTwoFactor()->create();
        $challenge = challengeFor($user);

        $this->withToken($challenge)->postJson(API_BASE.'/auth/two-factor', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'recovery_code']);
    });

    test('a customer token cannot answer the challenge', function () {
        $user = User::factory()->withTwoFactor()->create();

        $this->withToken(apiTokenFor($user))->postJson(API_BASE.'/auth/two-factor', ['code' => '123456'])
            ->assertForbidden();
    });

    test('the challenge token expires', function () {
        $user = User::factory()->withTwoFactor()->create();
        $challenge = challengeFor($user);

        $this->travel(6)->minutes();

        $this->withToken($challenge)->postJson(API_BASE.'/auth/two-factor', ['recovery_code' => 'recovery-code-1'])
            ->assertUnauthorized();
    });
});
