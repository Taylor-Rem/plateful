<?php

use App\Exceptions\PosProviderException;
use App\Services\Pos\Clover\CloverOAuthService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.clover', [
        'app_id' => 'sandbox-app-id',
        'app_secret' => 'sandbox-secret',
        'environment' => 'sandbox',
        'redirect' => 'https://admin.plateful.test/pos/clover/callback',
    ]);
});

it('builds an authorize url against the sandbox host with client id, state, and redirect', function () {
    $url = app(CloverOAuthService::class)->buildAuthorizeUrl('the-state');

    expect($url)->toStartWith('https://sandbox.dev.clover.com/oauth/v2/authorize?');
    expect($url)->toContain('client_id=sandbox-app-id');
    expect($url)->toContain('state=the-state');
    expect($url)->toContain(urlencode('https://admin.plateful.test/pos/clover/callback'));
    // Clover has no scope parameter — permissions live on the app itself.
    expect($url)->not->toContain('scope=');
});

it('targets the production authorize host when configured', function () {
    config()->set('services.clover.environment', 'production');

    $url = app(CloverOAuthService::class)->buildAuthorizeUrl('s');

    expect($url)->toStartWith('https://www.clover.com/oauth/v2/authorize?');
});

it('exchanges an authorization code for an expiring token pair', function () {
    Http::fake([
        'apisandbox.dev.clover.com/oauth/v2/token' => Http::response([
            'access_token' => 'access-abc',
            'access_token_expiration' => now()->addMinutes(30)->timestamp,
            'refresh_token' => 'refresh-xyz',
            'refresh_token_expiration' => now()->addDays(30)->timestamp,
        ]),
    ]);

    $tokens = app(CloverOAuthService::class)->exchangeCode('auth-code');

    expect($tokens->accessToken)->toBe('access-abc');
    expect($tokens->refreshToken)->toBe('refresh-xyz');
    expect($tokens->expiresAt->isFuture())->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://apisandbox.dev.clover.com/oauth/v2/token'
            && $request['client_id'] === 'sandbox-app-id'
            && $request['client_secret'] === 'sandbox-secret'
            && $request['code'] === 'auth-code';
    });
});

it('throws a POS provider exception when the token exchange fails', function () {
    Http::fake([
        'apisandbox.dev.clover.com/oauth/v2/token' => Http::response(['message' => 'bad code'], 400),
    ]);

    app(CloverOAuthService::class)->exchangeCode('nope');
})->throws(PosProviderException::class);

it('refreshes tokens against the refresh endpoint with only the client id', function () {
    Http::fake([
        'apisandbox.dev.clover.com/oauth/v2/refresh' => Http::response([
            'access_token' => 'access-new',
            'access_token_expiration' => now()->addMinutes(30)->timestamp,
            'refresh_token' => 'refresh-rotated',
            'refresh_token_expiration' => now()->addDays(30)->timestamp,
        ]),
    ]);

    $tokens = app(CloverOAuthService::class)->refreshToken('refresh-xyz');

    expect($tokens->accessToken)->toBe('access-new');
    expect($tokens->refreshToken)->toBe('refresh-rotated');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://apisandbox.dev.clover.com/oauth/v2/refresh'
            && $request['client_id'] === 'sandbox-app-id'
            && $request['refresh_token'] === 'refresh-xyz'
            && ! isset($request['client_secret']);
    });
});
