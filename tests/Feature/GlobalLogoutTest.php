<?php

use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function sessionRow(string $id, int $userId): array
{
    return [
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => time(),
    ];
}

it('purges every database session for the user on logout, across hosts', function () {
    config(['session.driver' => 'database']);

    $owner = User::factory()->create();
    $other = User::factory()->create();

    // Host-scoped sessions: the same user holds one session per host.
    DB::table('sessions')->insert([
        sessionRow('admin-host-session', $owner->id),
        sessionRow('primary-host-session', $owner->id),
        sessionRow('other-user-session', $other->id),
    ]);

    event(new Logout('web', $owner));

    expect(DB::table('sessions')->where('user_id', $owner->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);
});

it('touches nothing when the session driver is not database', function () {
    // The test environment runs the array driver by default.
    $owner = User::factory()->create();
    DB::table('sessions')->insert([sessionRow('some-session', $owner->id)]);

    event(new Logout('web', $owner));

    expect(DB::table('sessions')->where('user_id', $owner->id)->count())->toBe(1);
});

it('logs out on the primary host where the for-restaurants page lives', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post('http://plateful.test/logout')
        ->assertRedirect();

    expect(auth()->guest())->toBeTrue();
});
