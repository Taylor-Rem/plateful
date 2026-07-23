<?php

use App\Support\SalesTaxRates;

it('estimates a rate from a state code regardless of casing or padding', function () {
    expect(SalesTaxRates::estimateFor('NY'))->toBe(8.54)
        ->and(SalesTaxRates::estimateFor('ny'))->toBe(8.54)
        ->and(SalesTaxRates::estimateFor(' ny '))->toBe(8.54);
});

it('returns a real zero for states with no sales tax', function () {
    // 0.0 and null mean different things: Oregon genuinely charges nothing, so
    // its estimate must not be mistaken for "we have no guess".
    foreach (['OR', 'MT', 'NH', 'DE'] as $state) {
        expect(SalesTaxRates::estimateFor($state))->toBe(0.0);
    }
});

it('returns null when the state is unknown or missing', function () {
    expect(SalesTaxRates::estimateFor('XX'))->toBeNull()
        ->and(SalesTaxRates::estimateFor(''))->toBeNull()
        ->and(SalesTaxRates::estimateFor(null))->toBeNull();
});

it('covers every state plus DC with a plausible rate', function () {
    $all = SalesTaxRates::all();

    expect($all)->toHaveCount(51);

    foreach ($all as $state => $rate) {
        expect($state)->toMatch('/^[A-Z]{2}$/')
            ->and($rate)->toBeGreaterThanOrEqual(0.0)
            // The column is decimal(5,2) and validation caps at 30.
            ->and($rate)->toBeLessThanOrEqual(30.0);
    }
});
