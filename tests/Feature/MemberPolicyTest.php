<?php

use App\Models\User;
use App\Policies\MemberPolicy;

test('admin cannot remove themselves', function () {
    $admin = User::factory()->create();
    expect((new MemberPolicy)->delete($admin, $admin))->toBeFalse();
});

test('admin can remove another member', function () {
    $admin = User::factory()->create();
    $other = User::factory()->create();
    expect((new MemberPolicy)->delete($admin, $other))->toBeTrue();
});
