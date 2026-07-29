<?php

use App\Models\User;
use Pest\Browser\Playwright\Playwright;

// The admin console is domain-routed; this makes the plugin's in-process
// server present every request with the admin host so those routes match.
beforeEach(function () {
    Playwright::setHost('admin.plateful.test');
});

test('the two-factor challenge page renders after admin login', function () {
    User::factory()->superAdmin()->create(['email' => 'founder@example.test']);

    visit('/login')
        ->fill('email', 'founder@example.test')
        ->fill('password', 'password')
        ->click('Log in')
        ->assertPathIs('/two-factor-challenge')
        ->assertNoJavaScriptErrors()
        ->assertSee('Authentication code')
        ->assertSee('authenticator application');
});
