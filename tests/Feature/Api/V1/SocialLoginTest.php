<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    Cache::flush();
    fakeProviderJwks();

    config()->set('services.google.client_id', 'web-client-id.apps.googleusercontent.com');
    config()->set('services.google.app_client_ids', ['ios-client-id.apps.googleusercontent.com', 'android-client-id.apps.googleusercontent.com']);
    config()->set('services.apple.client_ids', ['fyi.plateful.app']);
});

describe('google', function () {
    test('a verified Google ID token creates the account and signs the device in', function () {
        $response = $this->postJson(API_BASE.'/auth/google', [
            'id_token' => googleIdToken(),
            'device_name' => 'iPhone',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.name', 'Ada Diner')
            ->assertJsonPath('data.user.emailVerified', true)
            ->assertJsonPath('data.user.linkedProviders', ['google']);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        expect($user->google_id)->toBe('google-sub-123')
            ->and($user->avatar)->toBe('https://avatars.test/ada.png');

        $this->withToken($response->json('data.token'))->getJson(API_BASE.'/me')->assertOk();
    });

    test('an account already linked by google_id is reused', function () {
        $user = User::factory()->create(['email' => 'other@example.com', 'google_id' => 'google-sub-123']);

        $this->postJson(API_BASE.'/auth/google', [
            'id_token' => googleIdToken(),
            'device_name' => 'iPhone',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);

        expect(User::query()->count())->toBe(1);
    });

    test('a verified email links to the existing password account', function () {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson(API_BASE.'/auth/google', [
            'id_token' => googleIdToken(),
            'device_name' => 'iPhone',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);

        expect($user->fresh()->google_id)->toBe('google-sub-123');
    });

    test('an unverified email never links to an existing account', function () {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson(API_BASE.'/auth/google', [
            'id_token' => googleIdToken(['email_verified' => false]),
            'device_name' => 'iPhone',
        ])->assertUnprocessable()->assertJsonValidationErrors(['id_token']);

        expect(User::query()->where('email', 'ada@example.com')->value('google_id'))->toBeNull();
    });

    test('the web client id is also an accepted audience', function () {
        $this->postJson(API_BASE.'/auth/google', [
            'id_token' => googleIdToken(['aud' => 'web-client-id.apps.googleusercontent.com']),
            'device_name' => 'iPhone',
        ])->assertOk();
    });

    test('a token for another app is rejected', function () {
        $this->postJson(API_BASE.'/auth/google', [
            'id_token' => googleIdToken(['aud' => 'someone-elses-client-id']),
            'device_name' => 'iPhone',
        ])->assertUnprocessable()->assertJsonValidationErrors(['id_token']);

        expect(User::query()->count())->toBe(0);
    });

    test('a token from the wrong issuer is rejected', function () {
        $this->postJson(API_BASE.'/auth/google', [
            'id_token' => googleIdToken(['iss' => 'https://evil.example.com']),
            'device_name' => 'iPhone',
        ])->assertUnprocessable()->assertJsonValidationErrors(['id_token']);
    });

    test('an expired token is rejected', function () {
        $this->postJson(API_BASE.'/auth/google', [
            'id_token' => googleIdToken(['iat' => time() - 7200, 'exp' => time() - 3600]),
            'device_name' => 'iPhone',
        ])->assertUnprocessable()->assertJsonValidationErrors(['id_token']);
    });

    test('a token signed by an unknown key is rejected', function () {
        $rogue = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $token = JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'ios-client-id.apps.googleusercontent.com',
            'sub' => 'google-sub-123',
            'email' => 'ada@example.com',
            'email_verified' => true,
            'exp' => time() + 3600,
        ], $rogue, 'RS256', 'test-key-1');

        $this->postJson(API_BASE.'/auth/google', ['id_token' => $token, 'device_name' => 'iPhone'])
            ->assertUnprocessable()->assertJsonValidationErrors(['id_token']);
    });

    test('garbage is rejected without contacting the provider more than once', function () {
        $this->postJson(API_BASE.'/auth/google', ['id_token' => 'not-a-jwt', 'device_name' => 'iPhone'])
            ->assertUnprocessable()->assertJsonValidationErrors(['id_token']);
    });

    test('google sign-in is unavailable when no client ids are configured', function () {
        config()->set('services.google.client_id', null);
        config()->set('services.google.app_client_ids', []);

        $this->postJson(API_BASE.'/auth/google', ['id_token' => googleIdToken(), 'device_name' => 'iPhone'])
            ->assertStatus(503);
    });
});

describe('apple', function () {
    test('a first Apple sign-in captures the name the app forwards', function () {
        $response = $this->postJson(API_BASE.'/auth/apple', [
            'id_token' => appleIdToken(),
            'device_name' => 'iPhone',
            'name' => 'Ada Diner',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.name', 'Ada Diner')
            ->assertJsonPath('data.user.email', 'ada@privaterelay.appleid.com')
            ->assertJsonPath('data.user.emailVerified', true)
            ->assertJsonPath('data.user.linkedProviders', ['apple']);

        expect(User::query()->where('email', 'ada@privaterelay.appleid.com')->value('apple_id'))->toBe('apple-sub-001.abc');
    });

    test('later Apple sign-ins without a name reuse the linked account', function () {
        $this->postJson(API_BASE.'/auth/apple', [
            'id_token' => appleIdToken(), 'device_name' => 'iPhone', 'name' => 'Ada Diner',
        ])->assertOk();

        $this->postJson(API_BASE.'/auth/apple', [
            'id_token' => appleIdToken(), 'device_name' => 'iPad',
        ])->assertOk()->assertJsonPath('data.user.name', 'Ada Diner');

        expect(User::query()->count())->toBe(1);
    });

    test('a new Apple account without a forwarded name falls back to the email', function () {
        $this->postJson(API_BASE.'/auth/apple', [
            'id_token' => appleIdToken(), 'device_name' => 'iPhone',
        ])->assertOk()->assertJsonPath('data.user.name', 'ada@privaterelay.appleid.com');
    });

    test('a verified Apple email links to the existing account', function () {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson(API_BASE.'/auth/apple', [
            'id_token' => appleIdToken(['email' => 'ada@example.com', 'email_verified' => true]),
            'device_name' => 'iPhone',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);

        expect($user->fresh()->apple_id)->toBe('apple-sub-001.abc');
    });

    test('a token for another bundle id is rejected', function () {
        $this->postJson(API_BASE.'/auth/apple', [
            'id_token' => appleIdToken(['aud' => 'com.other.app']),
            'device_name' => 'iPhone',
        ])->assertUnprocessable()->assertJsonValidationErrors(['id_token']);
    });

    test('apple sign-in is unavailable when no client ids are configured', function () {
        config()->set('services.apple.client_ids', []);

        $this->postJson(API_BASE.'/auth/apple', ['id_token' => appleIdToken(), 'device_name' => 'iPhone'])
            ->assertStatus(503);
    });
});
