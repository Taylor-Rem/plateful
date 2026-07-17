<?php

use App\Services\Delivery\DeliveryMarkup;

beforeEach(function (): void {
    config(['platform.stripe_variable_rate' => 0.029]);
});

it('grosses up the courier fee by margin and Stripe recovery', function () {
    // Plan §1.1 worked example: D = $9.00 at 4% → customer pays $9.64.
    expect(DeliveryMarkup::customerFeeCents(900, 4.0))->toBe(964);
    expect(DeliveryMarkup::marginCents(900, 4.0))->toBe(36);
});

it('scales the markup with the restaurant Plateful rate', function () {
    // At 0% the customer fee only recovers Stripe's variable cut, no margin.
    expect(DeliveryMarkup::marginCents(900, 0.0))->toBe(0);
    expect(DeliveryMarkup::customerFeeCents(900, 0.0))->toBe((int) round(900 / 0.971));

    // At 6% the margin grows proportionally.
    expect(DeliveryMarkup::marginCents(900, 6.0))->toBe(54);
});

it('recovers Stripe on the delivery line so the restaurant is left whole', function () {
    $rate = 0.029;
    $courier = 900;
    $dc = DeliveryMarkup::customerFeeCents($courier, 4.0);
    $margin = DeliveryMarkup::marginCents($courier, 4.0);

    // What Stripe pulls from the delivery line as application fee is courier +
    // margin; what Stripe keeps as its variable fee is rate × Dc. The remainder
    // left in the restaurant's account must cover neither — i.e. the restaurant
    // nets ~0 on delivery, bearing no Stripe fee on it (to within rounding).
    $restaurantKeepsFromDelivery = $dc - ($courier + $margin);
    $stripeVariableOnDelivery = (int) round($rate * $dc);

    expect(abs($restaurantKeepsFromDelivery - $stripeVariableOnDelivery))->toBeLessThanOrEqual(1);
});

it('falls back to no Stripe recovery if the rate is misconfigured', function () {
    config(['platform.stripe_variable_rate' => 0]);

    // With no rate, the customer fee is just courier + margin (no division blowup).
    expect(DeliveryMarkup::customerFeeCents(900, 4.0))->toBe(936);
});
