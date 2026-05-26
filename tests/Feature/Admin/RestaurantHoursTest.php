<?php

use App\Data\RestaurantData;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function hoursRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => "Marco's",
        'subdomain' => 'marcos',
        'email' => 'hello@m.test',
        'street' => '1',
        'city' => 'NY',
        'state' => 'NY',
        'postal_code' => '1',
    ]);
}

function hoursAdmin(Restaurant $r): User
{
    $u = User::create([
        'is_super_admin' => false,
        'name' => 'Owner',
        'email' => 'admin@m.test',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);
    $u->restaurants()->attach($r->id, ['role' => 'admin']);

    return $u;
}

test('admin can view the hours editor', function () {
    $r = hoursRestaurant();
    $u = hoursAdmin($r);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/hours")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Admin/TenantAdmin/Hours'));
});

test('admin can submit a full week schedule', function () {
    $r = hoursRestaurant();
    $u = hoursAdmin($r);

    $windows = [];
    for ($d = 0; $d < 7; $d++) {
        $windows[$d] = [
            ['opens_at' => '09:00', 'closes_at' => '17:00'],
        ];
    }

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/hours", [
            'windows' => $windows,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RestaurantHour::where('restaurant_id', $r->id)->count())->toBe(7);
});

test('opens_at equal to closes_at fails validation', function () {
    $r = hoursRestaurant();
    $u = hoursAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/hours", [
            'windows' => [
                3 => [['opens_at' => '09:00', 'closes_at' => '09:00']],
            ],
        ])
        ->assertSessionHasErrors('windows.3.0.closes_at');
});

test('overlapping windows on same day fail validation', function () {
    $r = hoursRestaurant();
    $u = hoursAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/hours", [
            'windows' => [
                3 => [
                    ['opens_at' => '09:00', 'closes_at' => '13:00'],
                    ['opens_at' => '12:00', 'closes_at' => '17:00'],
                ],
            ],
        ])
        ->assertSessionHasErrors();
});

test('empty windows for a day means closed that day', function () {
    $r = hoursRestaurant();
    $u = hoursAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/hours", [
            'windows' => [
                3 => [['opens_at' => '09:00', 'closes_at' => '17:00']],
                4 => [],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RestaurantHour::where('restaurant_id', $r->id)->where('day_of_week', 4)->count())->toBe(0);
    expect(RestaurantHour::where('restaurant_id', $r->id)->where('day_of_week', 3)->count())->toBe(1);
});

test('RestaurantData hoursByDay reflects saved schedule', function () {
    $r = hoursRestaurant();
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 1,
        'opens_at' => '10:00:00',
        'closes_at' => '14:00:00',
        'position' => 0,
    ]);

    $data = RestaurantData::fromModel($r->fresh());
    expect($data->hoursByDay[1])->toBe([
        ['opensAt' => '10:00', 'closesAt' => '14:00', 'position' => 0],
    ]);
    expect($data->hoursByDay[2])->toBe([]);
});
