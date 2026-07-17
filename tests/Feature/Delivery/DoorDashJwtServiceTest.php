<?php

use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Services\Delivery\DoorDash\DoorDashJwtService;

/**
 * @return array{header: array<string, mixed>, payload: array<string, mixed>, signingInput: string, signature: string}
 */
function decodeDoorDashJwt(string $token): array
{
    [$h, $p, $s] = explode('.', $token);

    return [
        'header' => json_decode(DoorDashJwtService::base64UrlDecode($h), true),
        'payload' => json_decode(DoorDashJwtService::base64UrlDecode($p), true),
        'signingInput' => $h.'.'.$p,
        'signature' => $s,
    ];
}

beforeEach(function (): void {
    config()->set('services.doordash.developer_id', 'dev-123');
    config()->set('services.doordash.key_id', 'key-456');
    // The signing secret is itself base64url-encoded (32 raw bytes here).
    config()->set('services.doordash.signing_secret', rtrim(strtr(base64_encode(str_repeat('s', 32)), '+/', '-_'), '='));
});

it('mints a token whose signature verifies against the decoded secret', function () {
    $token = app(DoorDashJwtService::class)->mint();

    $parts = decodeDoorDashJwt($token);

    // The verifier must decode the base64url secret to raw bytes before HMAC —
    // signing against the encoded string would verify here but fail at DoorDash.
    $secret = DoorDashJwtService::base64UrlDecode((string) config('services.doordash.signing_secret'));
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts['signingInput'], $secret, true)), '+/', '-_'), '=');

    expect($parts['signature'])->toBe($expected);
});

it('carries the DoorDash-specific header and claim set', function () {
    $parts = decodeDoorDashJwt(app(DoorDashJwtService::class)->mint());

    expect($parts['header']['alg'])->toBe('HS256');
    // DoorDash rejects a standard JWT without this version claim.
    expect($parts['header']['dd-ver'])->toBe('DD-JWT-V1');
    expect($parts['header']['kid'])->toBe('key-456');

    expect($parts['payload']['aud'])->toBe('doordash');
    expect($parts['payload']['iss'])->toBe('dev-123');
    expect($parts['payload']['kid'])->toBe('key-456');
    expect($parts['payload']['exp'])->toBeGreaterThan($parts['payload']['iat']);
});

it('fails clearly when platform credentials are missing', function () {
    config()->set('services.doordash.signing_secret', '');

    expect(fn () => app(DoorDashJwtService::class)->mint())
        ->toThrow(DeliveryProviderException::class, DeliveryProviderName::DoorDash->value);
});
