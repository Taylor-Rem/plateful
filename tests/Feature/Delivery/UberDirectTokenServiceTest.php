<?php

use App\Exceptions\DeliveryProviderException;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.uber_direct.client_id' => 'cid_platform',
        'services.uber_direct.client_secret' => 'csec_platform',
    ]);
});

/**
 * @return array<string, mixed>
 */
function uberTokenResponse(string $token = 'uber_tok_abc', int $expiresIn = 2_592_000): array
{
    return [
        'access_token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $expiresIn,
        'scope' => 'eats.deliveries',
    ];
}

it('mints a platform token with the client_credentials grant', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse())]);

    $token = app(UberDirectTokenService::class)->freshAccessToken();

    expect($token)->toBe('uber_tok_abc');

    Http::assertSent(fn (Request $r): bool => $r->url() === UberDirectTokenService::TOKEN_URL
        && $r['grant_type'] === 'client_credentials'
        && $r['scope'] === 'eats.deliveries'
        && $r['client_id'] === 'cid_platform'
        && $r['client_secret'] === 'csec_platform');
});

it('caches the minted token so a second call costs no grant', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse())]);

    $service = app(UberDirectTokenService::class);

    expect($service->freshAccessToken())->toBe('uber_tok_abc');
    expect($service->freshAccessToken())->toBe('uber_tok_abc');

    // The grant is capped at 100 requests/hour — a token still well inside its
    // life must never cost another one.
    Http::assertSentCount(1);
});

it('mints separate tokens per scope', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse())]);

    $service = app(UberDirectTokenService::class);
    $service->freshAccessToken(UberDirectTokenService::SCOPE_DELIVERIES);
    $service->freshAccessToken(UberDirectTokenService::SCOPE_ORGANIZATIONS);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r): bool => $r['scope'] === 'direct.organizations');
});

it('re-mints after forget() so a new sub-org becomes reachable', function () {
    // A token only authorizes the orgs that existed when it was minted
    // (verified live: a pre-provisioning token gets 403 for the new org), so
    // provisioning must be able to invalidate the cache.
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse())]);

    $service = app(UberDirectTokenService::class);
    $service->freshAccessToken();
    $service->forget();
    $service->freshAccessToken();

    Http::assertSentCount(2);
});

it('does not cache a token whose remaining life is inside the refresh window', function () {
    // One hour of life is less than the 24h refresh window: caching it would
    // hand out a token that could die mid-flight.
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse('short_tok', 3600))]);

    $service = app(UberDirectTokenService::class);
    expect($service->freshAccessToken())->toBe('short_tok');
    expect($service->freshAccessToken())->toBe('short_tok');

    Http::assertSentCount(2);
});

it('refuses to call Uber at all when the platform credentials are missing', function () {
    config(['services.uber_direct.client_id' => null]);
    Http::fake();

    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken())
        ->toThrow(DeliveryProviderException::class, 'not configured');

    Http::assertNothingSent();
});

it('names the credential Uber actually rejected', function () {
    // Shapes below are what the live sandbox actually returns, not the docs:
    // 401 invalid_client for an unknown id, 403 access_denied for a bad secret.
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(
            ['error' => 'access_denied', 'error_description' => 'AccessDenied: client secret mismatch'],
            403,
        ),
    ]);

    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken())
        ->toThrow(DeliveryProviderException::class, 'rejected the platform Client Secret');
});

it('explains an unprovisioned scope rather than reporting a bare status code', function () {
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(
            ['error' => 'invalid_scope', 'error_description' => 'scope(s) are invalid'],
            400,
        ),
    ]);

    // An account without the scope reports invalid_scope, which reads like a
    // code bug unless we say what it really means — and it must name the scope
    // that was actually asked for.
    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken(UberDirectTokenService::SCOPE_ORGANIZATIONS))
        ->toThrow(DeliveryProviderException::class, 'not enabled for the direct.organizations scope');
});

it('does not cache anything when the grant fails', function () {
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::sequence()
            ->push(['error' => 'invalid_client'], 401)
            ->push(uberTokenResponse('recovered_tok')),
    ]);

    $service = app(UberDirectTokenService::class);

    expect(fn () => $service->freshAccessToken())->toThrow(DeliveryProviderException::class);
    expect($service->freshAccessToken())->toBe('recovered_tok');
});

it('treats a 200 with no access token as a failure', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(['token_type' => 'Bearer'])]);

    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken())
        ->toThrow(DeliveryProviderException::class, 'no access token');
});

it('requestToken never touches the cache', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse('probe_tok'))]);

    $token = app(UberDirectTokenService::class)->requestToken();

    expect($token->accessToken)->toBe('probe_tok');
    expect(Cache::get('uber_direct.platform_token.eats.deliveries'))->toBeNull();
});
