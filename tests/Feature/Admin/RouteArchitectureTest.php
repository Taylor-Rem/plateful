<?php

/*
 * The webhook URLs are registered with external providers (Stripe, Uber,
 * DoorDash); changing them breaks live integrations silently. These tests pin
 * the exact URLs so a route reorganization can never move them unnoticed.
 */
test('webhook URLs stay pinned to their externally registered paths', function (string $name, string $url) {
    expect(route($name))->toBe($url);
})->with([
    'stripe' => ['stripe.webhook', 'http://admin.plateful.test/stripe/webhook'],
    'uber' => ['webhooks.uber', 'http://admin.plateful.test/webhooks/uber'],
    'doordash' => ['webhooks.doordash', 'http://admin.plateful.test/webhooks/doordash'],
]);

test('tenant admin routes resolve on the admin host', function () {
    expect(route('admin.restaurant.dashboard', ['restaurant' => 'marcos']))
        ->toBe('http://admin.plateful.test/marcos/dashboard');
});

test('super admin routes resolve under the super prefix', function () {
    expect(route('admin.super.restaurants.index'))
        ->toBe('http://admin.plateful.test/super/restaurants');
});
