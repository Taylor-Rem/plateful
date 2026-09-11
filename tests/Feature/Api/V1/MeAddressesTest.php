<?php

use App\Models\Address;
use App\Models\User;

require_once __DIR__.'/ApiHelpers.php';

function addressPayload(array $overrides = []): array
{
    return ['label' => 'Home', 'street' => '285 Fulton St', 'city' => 'New York', 'state' => 'NY', 'postal_code' => '10006', ...$overrides];
}

test('addresses can be listed, created, updated, and deleted, with one default', function () {
    $user = User::factory()->create();
    $bearer = apiTokenFor($user);

    $first = $this->withToken($bearer)->postJson(API_BASE.'/me/addresses', addressPayload(['is_default' => true]))
        ->assertCreated()
        ->assertJsonPath('data.isDefault', true)
        ->assertJsonPath('data.country', 'US')
        ->json('data.id');

    $second = $this->withToken($bearer)->postJson(API_BASE.'/me/addresses', addressPayload(['label' => 'Work', 'street' => '1 Main St', 'is_default' => true]))
        ->assertCreated()->json('data.id');

    expect(Address::find($first)->is_default)->toBeFalse()
        ->and(Address::find($second)->is_default)->toBeTrue();

    $this->withToken($bearer)->getJson(API_BASE.'/me/addresses')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second);

    $this->withToken($bearer)->patchJson(API_BASE."/me/addresses/{$first}", addressPayload(['instructions' => 'Ring twice']))
        ->assertOk()->assertJsonPath('data.instructions', 'Ring twice');

    $this->withToken($bearer)->deleteJson(API_BASE."/me/addresses/{$second}")->assertNoContent();
    expect(Address::find($second))->toBeNull();
});

test('another customer\'s address is a 404 and the street is required', function () {
    $user = User::factory()->create();
    $other = Address::create(['user_id' => User::factory()->create()->id, ...addressPayload(), 'country' => 'US', 'is_default' => false]);

    $this->withToken(apiTokenFor($user))->patchJson(API_BASE."/me/addresses/{$other->id}", addressPayload())->assertNotFound();
    $this->withToken(apiTokenFor($user))->deleteJson(API_BASE."/me/addresses/{$other->id}")->assertNotFound();
    $this->withToken(apiTokenFor($user))->postJson(API_BASE.'/me/addresses', addressPayload(['street' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors(['street']);
});
