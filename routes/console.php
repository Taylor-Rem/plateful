<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily sweep that suspends restaurants whose free trial ended without a
// paying subscription. Runs at 03:00 UTC to avoid peak hours.
Schedule::command('platform:suspend-expired-trials')->dailyAt('03:00');
