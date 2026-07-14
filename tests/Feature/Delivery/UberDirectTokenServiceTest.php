<?php

use App\Enums\DeliveryIntegrationStatus;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryIntegration;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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

it('mints a token with the client_credentials grant', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse())]);

    $integration = DeliveryIntegration::factory()->withoutToken()->create([
        'client_id' => 'cid_live',
        'client_secret' => 'csec_live',
    ]);

    $token = app(UberDirectTokenService::class)->freshAccessToken($integration);

    expect($token)->toBe('uber_tok_abc');

    Http::assertSent(fn (Request $r): bool => $r->url() === UberDirectTokenService::TOKEN_URL
        && $r['grant_type'] === 'client_credentials'
        && $r['scope'] === 'eats.deliveries'
        && $r['client_id'] === 'cid_live'
        && $r['client_secret'] === 'csec_live');
});

it('persists the minted token and its expiry so it is not re-requested', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse())]);

    $integration = DeliveryIntegration::factory()->withoutToken()->create();

    app(UberDirectTokenService::class)->freshAccessToken($integration);

    $fresh = $integration->fresh();
    expect($fresh->access_token)->toBe('uber_tok_abc');
    expect($fresh->status)->toBe(DeliveryIntegrationStatus::Connected);
    expect($fresh->token_expires_at->isAfter(now()->addDays(29)))->toBeTrue();
});

it('reuses a stored token without calling Uber', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse('should_not_be_used'))]);

    $integration = DeliveryIntegration::factory()->create([
        'access_token' => 'stored_tok',
        'token_expires_at' => now()->addDays(20),
    ]);

    $token = app(UberDirectTokenService::class)->freshAccessToken($integration);

    expect($token)->toBe('stored_tok');

    // The grant is capped at 100 requests/hour — a token still well inside its
    // life must never cost one.
    Http::assertNothingSent();
});

it('re-mints a token that is inside the refresh window but not yet expired', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse('rotated_tok'))]);

    $integration = DeliveryIntegration::factory()->create([
        'access_token' => 'nearly_stale_tok',
        'token_expires_at' => now()->addHours(2),
    ]);

    $token = app(UberDirectTokenService::class)->freshAccessToken($integration);

    expect($token)->toBe('rotated_tok');
    Http::assertSentCount(1);
});

it('re-mints an already-expired token', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse('replacement_tok'))]);

    $integration = DeliveryIntegration::factory()->tokenExpired()->create([
        'access_token' => 'dead_tok',
    ]);

    expect(app(UberDirectTokenService::class)->freshAccessToken($integration))->toBe('replacement_tok');
});

it('marks the integration errored with an actionable reason when Uber rejects the credentials', function () {
    // Shapes below are what the live sandbox actually returns, not the docs.
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(
            ['error' => 'invalid_client', 'error_description' => 'client ID is invalid'],
            401,
        ),
    ]);

    $integration = DeliveryIntegration::factory()->withoutToken()->create();

    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken($integration))
        ->toThrow(DeliveryProviderException::class);

    $fresh = $integration->fresh();
    expect($fresh->status)->toBe(DeliveryIntegrationStatus::Error);
    expect($fresh->last_error)->toContain('does not recognize this Client ID');
    expect($fresh->access_token)->toBeNull();
});

it('blames the secret, not the client id, when only the secret is wrong', function () {
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(
            ['error' => 'access_denied', 'error_description' => 'AccessDenied: client secret mismatch'],
            403,
        ),
    ]);

    $integration = DeliveryIntegration::factory()->withoutToken()->create();

    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken($integration))
        ->toThrow(DeliveryProviderException::class, 'rejected the Client Secret');
});

it('explains an unprovisioned account rather than reporting a bare status code', function () {
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(
            ['error' => 'invalid_scope', 'error_description' => 'scope(s) are invalid'],
            400,
        ),
    ]);

    $integration = DeliveryIntegration::factory()->withoutToken()->create();

    // An account without Direct API access reports invalid_scope, which reads
    // like a code bug unless we say what it really means.
    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken($integration))
        ->toThrow(DeliveryProviderException::class, 'not enabled for the eats.deliveries scope');
});

it('clears a stale token when a re-mint fails, so nothing keeps using it', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

    $integration = DeliveryIntegration::factory()->tokenExpired()->create([
        'access_token' => 'expired_tok',
    ]);

    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken($integration))
        ->toThrow(DeliveryProviderException::class);

    expect($integration->fresh()->access_token)->toBeNull();
});

it('refuses to call Uber at all when credentials are missing', function () {
    Http::fake();

    $integration = DeliveryIntegration::factory()->disconnected()->create();

    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken($integration))
        ->toThrow(DeliveryProviderException::class, 'not configured');

    Http::assertNothingSent();
});

it('treats a 200 with no access token as a failure', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(['token_type' => 'Bearer'])]);

    $integration = DeliveryIntegration::factory()->withoutToken()->create();

    expect(fn () => app(UberDirectTokenService::class)->freshAccessToken($integration))
        ->toThrow(DeliveryProviderException::class, 'no access token');
});

it('trusts expires_in from the response over the documented lifetime', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse('short_tok', 3600))]);

    $integration = DeliveryIntegration::factory()->withoutToken()->create();

    app(UberDirectTokenService::class)->freshAccessToken($integration);

    expect($integration->fresh()->token_expires_at->isBefore(now()->addHours(2)))->toBeTrue();
});

it('exchanges credentials without writing to the database', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberTokenResponse('probe_tok'))]);

    $integration = DeliveryIntegration::factory()->withoutToken()->create();

    $token = app(UberDirectTokenService::class)->requestToken('probe_id', 'probe_secret');

    expect($token->accessToken)->toBe('probe_tok');
    // The admin form verifies pasted credentials before saving them; probing
    // must not mutate the integration it is checking against.
    expect($integration->fresh()->access_token)->toBeNull();
});
