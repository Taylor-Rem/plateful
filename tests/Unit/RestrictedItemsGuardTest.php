<?php

use App\Services\Delivery\RestrictedItemsGuard;

it('flags restricted items by category', function (string $name, string $expectedTerm) {
    $violations = (new RestrictedItemsGuard)->violations([$name]);

    expect($violations)->toHaveCount(1);
    expect($violations[0]['item'])->toBe($name);
    expect($violations[0]['term'])->toBe($expectedTerm);
})->with([
    'alcohol' => ['Bud Light Beer', 'beer'],
    'wine' => ['House Red Wine (glass)', 'wine'],
    'spirits' => ['Tito\'s Vodka shot', 'vodka'],
    'cocktail' => ['Classic Margarita', 'margarita'],
    'tobacco' => ['Marlboro Cigarettes', 'cigarettes'],
    'cannabis' => ['CBD Gummies', 'cbd'],
    'weapons' => ['9mm Ammunition', 'ammunition'],
    'explosives' => ['Sparkler Fireworks Pack', 'fireworks'],
]);

it('does not flag food that borrows a restricted word', function (string $name) {
    expect((new RestrictedItemsGuard)->violations([$name]))->toBe([]);
})->with([
    'root beer' => 'Root Beer Float',
    'beer battered' => 'Beer-Battered Cod',
    'beer cheese' => 'Pretzel with Beer Cheese',
    'margherita is not margarita' => 'Margherita Pizza',
    'gunpowder is a dish' => 'Gunpowder Chicken',
    'ginger contains gin' => 'Ginger Ale',
    'wine vinegar' => 'Salad with Red Wine Vinegar Dressing',
    'bourbon glaze' => 'Bourbon Glazed Salmon',
    'shrimp cocktail' => 'Jumbo Shrimp Cocktail',
    'plain food' => 'Pepperoni Pizza',
]);

it('screens a whole order and names every offender', function () {
    $guard = new RestrictedItemsGuard;

    $violations = $guard->violations(['Pepperoni Pizza', 'Bud Light Beer', 'CBD Gummies']);

    expect($violations)->toHaveCount(2);

    $reason = $guard->failureReason($violations);
    expect($reason)->toStartWith(RestrictedItemsGuard::FAILURE_PREFIX);
    expect($reason)->toContain('Bud Light Beer (beer)');
    expect($reason)->toContain('CBD Gummies (cbd)');
});
