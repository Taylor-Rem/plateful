<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Queue-worker backstop: an order left Authorized past the courier deadline
// means a stranded card hold. Reported through report() so Sentry alerts on
// it once a DSN is set (see AlertStaleAuthorizationsCommand).
Schedule::command('orders:alert-stale-authorizations')->hourly();
