<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        // Dummy Stripe credentials so the Stripe client / webhook signature
        // verification can construct in tests without real keys.
        config()->set('services.stripe.secret', 'sk_test_dummy');
        config()->set('services.stripe.webhook_secret', 'whsec_test_dummy');

        // Any HTTP call not matched by an Http::fake is a bug, not a request.
        // The opt-in live-sandbox suites re-allow real requests per-file.
        Http::preventStrayRequests();
    })
    ->use(RefreshDatabase::class)
    ->in('Feature');
