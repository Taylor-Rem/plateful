<?php

use App\Services\Delivery\UberDirect\UberDirectAddress;

/**
 * @return array<string, mixed>
 */
function addressSnapshot(array $overrides = []): array
{
    return array_merge([
        'street' => '285 Fulton St',
        'street2' => 'Apt 4B',
        'city' => 'New York',
        'state' => 'NY',
        'postal_code' => '10006',
        'country' => 'US',
        'instructions' => 'Buzz twice',
    ], $overrides);
}

it('encodes an address as a JSON string in Uber’s documented shape', function () {
    $encoded = UberDirectAddress::fromSnapshot(addressSnapshot());

    expect($encoded)->toBeString();
    expect(json_decode($encoded, true))->toBe([
        'street_address' => ['285 Fulton St', 'Apt 4B'],
        'city' => 'New York',
        'state' => 'NY',
        'zip_code' => '10006',
        'country' => 'US',
    ]);
});

it('is deterministic — the same snapshot always encodes byte-identically', function () {
    // The whole reason this class exists: Uber rejects a create whose address
    // differs from its quote's with `delivery location changed`.
    expect(UberDirectAddress::fromSnapshot(addressSnapshot()))
        ->toBe(UberDirectAddress::fromSnapshot(addressSnapshot()));
});

it('encodes identically regardless of the snapshot’s key order', function () {
    $forward = addressSnapshot();
    $reversed = array_reverse($forward, preserve_keys: true);

    // A snapshot round-tripped through JSON or rebuilt elsewhere may arrive
    // with different key order; the payload must not change because of it.
    expect(UberDirectAddress::fromSnapshot($reversed))
        ->toBe(UberDirectAddress::fromSnapshot($forward));
});

it('keeps the unit slot present but empty when there is no apartment', function () {
    $encoded = UberDirectAddress::fromSnapshot(addressSnapshot(['street2' => null]));

    expect(json_decode($encoded, true)['street_address'])->toBe(['285 Fulton St', '']);
});

it('omits the instructions, which are not part of the address Uber matches on', function () {
    $withNote = UberDirectAddress::fromSnapshot(addressSnapshot(['instructions' => 'Buzz twice']));
    $withoutNote = UberDirectAddress::fromSnapshot(addressSnapshot(['instructions' => null]));

    // Dropoff notes travel as their own field. If they leaked in here, editing
    // a note between quote and create would break the address match.
    expect($withNote)->toBe($withoutNote);
    expect($withNote)->not->toContain('Buzz');
});

it('defaults a missing country to US rather than emitting an empty one', function () {
    $encoded = UberDirectAddress::fromSnapshot(addressSnapshot(['country' => '']));

    expect(json_decode($encoded, true)['country'])->toBe('US');
});

it('does not escape slashes or unicode, so the payload stays stable', function () {
    $encoded = UberDirectAddress::fromSnapshot(addressSnapshot(['street' => '1/2 Cañada Rd']));

    expect($encoded)->toContain('1/2');
    expect($encoded)->toContain('Cañada');
});
