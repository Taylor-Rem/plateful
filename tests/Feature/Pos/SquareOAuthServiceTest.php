<?php

use App\Exceptions\PosProviderException;
use App\Services\Pos\Square\SquareOAuthService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.square', [
        'application_id' => 'sandbox-app-id',
        'application_secret' => 'sandbox-secret',
        'environment' => 'sandbox',
        'redirect' => 'https://admin.plateful.test/pos/square/callback',
    ]);
});

it('builds an authorize url against the sandbox host with our scopes and state', function () {
    $url = app(SquareOAuthService::class)->buildAuthorizeUrl('the-state');

    expect($url)->toStartWith('https://connect.squareupsandbox.com/oauth2/authorize?');
    expect($url)->toContain('client_id=sandbox-app-id');
    expect($url)->toContain('state=the-state');
    expect($url)->toContain(urlencode('https://admin.plateful.test/pos/square/callback'));
    expect($url)->toContain('ORDERS_WRITE');
});

it('targets the production host when configured', function () {
    config()->set('services.square.environment', 'production');

    $url = app(SquareOAuthService::class)->buildAuthorizeUrl('s');

    expect($url)->toStartWith('https://connect.squareup.com/oauth2/authorize?');
});

it('exchanges an authorization code for tokens', function () {
    Http::fake([
        'connect.squareupsandbox.com/oauth2/token' => Http::response([
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'merchant_id' => 'MERCHANT1',
        ]),
    ]);

    $tokens = app(SquareOAuthService::class)->exchangeCode('auth-code');

    expect($tokens->accessToken)->toBe('access-abc');
    expect($tokens->refreshToken)->toBe('refresh-xyz');
    expect($tokens->merchantId)->toBe('MERCHANT1');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://connect.squareupsandbox.com/oauth2/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'auth-code'
            && $request->hasHeader('Square-Version');
    });
});

it('throws a POS provider exception when the token exchange fails', function () {
    Http::fake([
        'connect.squareupsandbox.com/oauth2/token' => Http::response(['message' => 'bad code'], 400),
    ]);

    app(SquareOAuthService::class)->exchangeCode('nope');
})->throws(PosProviderException::class);

it('refreshes tokens with the refresh grant', function () {
    Http::fake([
        'connect.squareupsandbox.com/oauth2/token' => Http::response([
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-xyz',
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'merchant_id' => 'MERCHANT1',
        ]),
    ]);

    $tokens = app(SquareOAuthService::class)->refreshToken('refresh-xyz');

    expect($tokens->accessToken)->toBe('access-new');
    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token');
});

it('picks the first active location', function () {
    Http::fake([
        'connect.squareupsandbox.com/v2/locations' => Http::response([
            'locations' => [
                ['id' => 'L_INACTIVE', 'status' => 'INACTIVE'],
                ['id' => 'L_ACTIVE', 'status' => 'ACTIVE'],
            ],
        ]),
    ]);

    $locationId = app(SquareOAuthService::class)->fetchPrimaryLocationId('access-abc');

    expect($locationId)->toBe('L_ACTIVE');
});

it('falls back to the first location when none are active', function () {
    Http::fake([
        'connect.squareupsandbox.com/v2/locations' => Http::response([
            'locations' => [['id' => 'L_ONLY', 'status' => 'INACTIVE']],
        ]),
    ]);

    expect(app(SquareOAuthService::class)->fetchPrimaryLocationId('t'))->toBe('L_ONLY');
});

it('revokes a token best-effort and reports success', function () {
    Http::fake([
        'connect.squareupsandbox.com/oauth2/revoke' => Http::response(['success' => true]),
    ]);

    expect(app(SquareOAuthService::class)->revoke('access-abc'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Client sandbox-secret'));
});
