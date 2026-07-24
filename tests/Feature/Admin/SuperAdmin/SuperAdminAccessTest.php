<?php

use App\Models\User;

const SUPER_ACCESS_BASE = 'http://admin.plateful.test';

test('guests hitting a super route are redirected to login, not 403ed', function () {
    $response = $this->get(SUPER_ACCESS_BASE.'/super/restaurants');

    $response->assertRedirect(SUPER_ACCESS_BASE.'/login');
});

test('authenticated non-admin users get 403 on super routes', function (string $path) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(SUPER_ACCESS_BASE.$path);

    $response->assertForbidden();
})->with([
    'restaurants' => ['/super/restaurants'],
    'earnings' => ['/super/earnings'],
    'admins' => ['/super/admins'],
]);

test('super admins pass through to super routes', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($superAdmin)->get(SUPER_ACCESS_BASE.'/super/restaurants');

    $response->assertOk();
});
