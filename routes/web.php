<?php

use Illuminate\Support\Facades\Route;

Route::domain(config('platform.primary_domain'))->group(function () {
    Route::inertia('/', 'Welcome')->name('home');
});
