<?php

use App\Enums\RestaurantRole;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;

const SUPER_USERS_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function usersActingSuper(): User
{
    // A second super admin so the acting user is never "the last one" and the
    // guard under test is the one the case actually targets. Emails are pinned:
    // a random faker email can collide with the search test's terms ("ada").
    User::factory()->superAdmin()->create(['name' => 'Backup Super', 'email' => 'backup.super@plateful.test']);

    return User::factory()->superAdmin()->create(['name' => 'Acting Super', 'email' => 'acting.super@plateful.test']);
}

test('the roster lists every kind of account, including orphans', function () {
    $super = usersActingSuper();
    $restaurant = Restaurant::factory()->create();

    $admin = User::factory()->create(['name' => 'Ada Admin']);
    $admin->restaurants()->attach($restaurant, ['role' => RestaurantRole::Admin->value]);

    $customer = User::factory()->create(['name' => 'Cal Customer']);
    $customer->customerRestaurants()->attach($restaurant);

    User::factory()->create(['name' => 'Ozzy Orphan']);

    $response = $this->actingAs($super)->get(SUPER_USERS_BASE.'/super/users');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/SuperAdmin/Users/Index')
        // 2 supers + admin + customer + orphan
        ->has('users', 5)
        ->where('filterCounts.all', 5)
        ->where('filterCounts.admins', 3)
        ->where('filterCounts.customers', 1)
        ->where('filterCounts.deleted', 0));

    $types = collect($response->viewData('page')['props']['users'])
        ->pluck('type', 'name');

    expect($types['Ada Admin'])->toBe('admin')
        ->and($types['Cal Customer'])->toBe('customer')
        ->and($types['Ozzy Orphan'])->toBe('orphan')
        ->and($types['Acting Super'])->toBe('super');
});

test('the filters narrow the roster', function () {
    $super = usersActingSuper();
    $restaurant = Restaurant::factory()->create();

    $admin = User::factory()->create(['name' => 'Ada Admin']);
    $admin->restaurants()->attach($restaurant, ['role' => RestaurantRole::Admin->value]);

    $customer = User::factory()->create(['name' => 'Cal Customer']);
    $customer->customerRestaurants()->attach($restaurant);

    $gone = User::factory()->create(['name' => 'Dan Deleted']);
    $gone->delete();

    $this->actingAs($super)
        ->get(SUPER_USERS_BASE.'/super/users?filter=customers')
        ->assertInertia(fn ($page) => $page
            ->has('users', 1)
            ->where('users.0.name', 'Cal Customer'));

    $this->actingAs($super)
        ->get(SUPER_USERS_BASE.'/super/users?filter=deleted')
        ->assertInertia(fn ($page) => $page
            ->has('users', 1)
            ->where('users.0.name', 'Dan Deleted')
            ->where('users.0.isDeleted', true));

    // Deleted accounts stay out of the live tabs.
    $this->actingAs($super)
        ->get(SUPER_USERS_BASE.'/super/users?filter=all')
        ->assertInertia(fn ($page) => $page->has('users', 4));
});

test('search matches name and email case-insensitively', function () {
    $super = usersActingSuper();
    User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
    User::factory()->create(['name' => 'Bob Baker', 'email' => 'bob@example.com']);

    $this->actingAs($super)
        ->get(SUPER_USERS_BASE.'/super/users?search=ADA')
        ->assertInertia(fn ($page) => $page
            ->has('users', 1)
            ->where('users.0.name', 'Ada Admin'));

    $this->actingAs($super)
        ->get(SUPER_USERS_BASE.'/super/users?search=bob@EXAMPLE')
        ->assertInertia(fn ($page) => $page
            ->has('users', 1)
            ->where('users.0.name', 'Bob Baker'));
});

test('the roster paginates', function () {
    $super = usersActingSuper();
    User::factory()->count(30)->create();

    $this->actingAs($super)
        ->get(SUPER_USERS_BASE.'/super/users')
        ->assertInertia(fn ($page) => $page
            ->has('users', 25)
            ->where('pagination.total', 32)
            ->where('pagination.lastPage', 2));

    $this->actingAs($super)
        ->get(SUPER_USERS_BASE.'/super/users?page=2')
        ->assertInertia(fn ($page) => $page
            ->has('users', 7)
            ->where('pagination.currentPage', 2));
});

test('a row flags the restaurants the user is the sole admin of', function () {
    $super = usersActingSuper();
    $solo = Restaurant::factory()->create(['name' => 'Solo Pizza']);
    $shared = Restaurant::factory()->create(['name' => 'Shared Pizza']);

    $admin = User::factory()->create(['name' => 'Ada Admin']);
    $admin->restaurants()->attach($solo, ['role' => RestaurantRole::Admin->value]);
    $admin->restaurants()->attach($shared, ['role' => RestaurantRole::Admin->value]);

    $other = User::factory()->create();
    $other->restaurants()->attach($shared, ['role' => RestaurantRole::Admin->value]);

    $response = $this->actingAs($super)->get(SUPER_USERS_BASE.'/super/users?search=Ada');

    $row = $response->viewData('page')['props']['users'][0];
    $soleAdminOf = collect($row['restaurants'])->where('isSoleAdmin', true)->pluck('name');

    expect($soleAdminOf->all())->toBe(['Solo Pizza']);
});

test('the detail page reports the account footprint', function () {
    $super = usersActingSuper();
    $restaurant = Restaurant::factory()->create(['name' => 'Marcos']);

    $customer = User::factory()->create(['name' => 'Cal Customer']);
    $customer->customerRestaurants()->attach($restaurant, [
        'total_orders' => 2,
        'total_spent_cents' => 3000,
    ]);
    Order::factory()->for($restaurant)->create(['user_id' => $customer->id, 'total_cents' => 1000]);
    Order::factory()->for($restaurant)->create(['user_id' => $customer->id, 'total_cents' => 2000]);

    $this->actingAs($super)
        ->get(SUPER_USERS_BASE."/super/users/{$customer->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Admin/SuperAdmin/Users/Show')
            ->where('user.name', 'Cal Customer')
            ->where('user.type', 'customer')
            ->where('impact.ordersCount', 2)
            ->where('impact.lifetimeSpendCents', 3000)
            ->has('impact.customerRestaurants', 1)
            ->where('impact.customerRestaurants.0.name', 'Marcos')
            ->where('account.hasGoogleLink', false));
});

test('the detail page still opens for a deleted account', function () {
    $super = usersActingSuper();
    $gone = User::factory()->create(['name' => 'Dan Deleted']);
    $gone->delete();

    $this->actingAs($super)
        ->get(SUPER_USERS_BASE."/super/users/{$gone->id}")
        ->assertInertia(fn ($page) => $page
            ->where('user.name', 'Dan Deleted')
            ->where('user.isDeleted', true));
});

test('super admin can soft delete an account, which frees its email', function () {
    $super = usersActingSuper();
    $target = User::factory()->create(['email' => 'freed@example.com']);

    $this->actingAs($super)
        ->delete(SUPER_USERS_BASE."/super/users/{$target->id}")
        ->assertRedirect(route('admin.super.users.index'))
        ->assertSessionHas('success');

    expect(User::find($target->id))->toBeNull();
    expect(User::withTrashed()->find($target->id)->trashed())->toBeTrue();

    // The freed email is usable by a brand new account.
    expect(fn () => User::factory()->create(['email' => 'freed@example.com']))->not->toThrow(Exception::class);
});

test('a soft-deleted admin cannot sign in', function () {
    $target = User::factory()->create(['email' => 'gone@example.com']);
    $target->delete();

    $this->post(SUPER_USERS_BASE.'/login', [
        'email' => 'gone@example.com',
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('you cannot delete your own account from the roster', function () {
    $super = usersActingSuper();

    $this->actingAs($super)
        ->delete(SUPER_USERS_BASE."/super/users/{$super->id}")
        ->assertSessionHas('error');

    expect(User::find($super->id))->not->toBeNull();
});

test('deleting one of two super admins is allowed', function () {
    $actor = User::factory()->superAdmin()->create();
    $peer = User::factory()->superAdmin()->create();

    $this->actingAs($actor)
        ->delete(SUPER_USERS_BASE."/super/users/{$peer->id}")
        ->assertRedirect(route('admin.super.users.index'))
        ->assertSessionHas('success');

    expect(User::find($peer->id))->toBeNull();
});

test('the last-super-admin guard counts only live accounts', function () {
    // Over HTTP the self-delete guard already covers this case — the actor must
    // be a live super admin, so any *other* target means at least two exist. The
    // guard is the defensive backstop, so assert it at the model level.
    $solo = User::factory()->superAdmin()->create();
    expect($solo->isLastSuperAdmin())->toBeTrue();

    $peer = User::factory()->superAdmin()->create();
    expect($solo->fresh()->isLastSuperAdmin())->toBeFalse();

    // A trashed super admin can't sign in, so it doesn't keep the platform safe.
    $peer->delete();
    expect($solo->fresh()->isLastSuperAdmin())->toBeTrue();
});

test('super admin can restore a deleted account', function () {
    $super = usersActingSuper();
    $target = User::factory()->create(['name' => 'Dan Deleted']);
    $target->delete();

    $this->actingAs($super)
        ->post(SUPER_USERS_BASE."/super/users/{$target->id}/restore")
        ->assertRedirect(route('admin.super.users.show', $target->id))
        ->assertSessionHas('success');

    expect(User::find($target->id))->not->toBeNull();
    expect(User::find($target->id)->trashed())->toBeFalse();
});

test('restore is blocked when a live account now holds the email', function () {
    $super = usersActingSuper();
    $target = User::factory()->create(['email' => 'reused@example.com']);
    $target->delete();

    User::factory()->create(['email' => 'reused@example.com']);

    $this->actingAs($super)
        ->post(SUPER_USERS_BASE."/super/users/{$target->id}/restore")
        ->assertSessionHas('error');

    expect(User::withTrashed()->find($target->id)->trashed())->toBeTrue();
});

test('restore is blocked when a live account now holds the google link', function () {
    $super = usersActingSuper();
    $target = User::factory()->create([
        'email' => 'old@example.com',
        'google_id' => 'google-123',
    ]);
    $target->delete();

    User::factory()->create([
        'email' => 'new@example.com',
        'google_id' => 'google-123',
    ]);

    $this->actingAs($super)
        ->post(SUPER_USERS_BASE."/super/users/{$target->id}/restore")
        ->assertSessionHas('error');

    expect(User::withTrashed()->find($target->id)->trashed())->toBeTrue();
});

test('super admin can permanently delete an order-less account', function () {
    $super = usersActingSuper();
    $target = User::factory()->create();
    $target->delete();

    $this->actingAs($super)
        ->delete(SUPER_USERS_BASE."/super/users/{$target->id}/force")
        ->assertRedirect(route('admin.super.users.index'))
        ->assertSessionHas('success');

    expect(User::withTrashed()->find($target->id))->toBeNull();
});

test('permanent delete is refused for an account with order history', function () {
    $super = usersActingSuper();
    $restaurant = Restaurant::factory()->create();
    $target = User::factory()->create();
    Order::factory()->for($restaurant)->create(['user_id' => $target->id]);
    $target->delete();

    $this->actingAs($super)
        ->delete(SUPER_USERS_BASE."/super/users/{$target->id}/force")
        ->assertSessionHas('error');

    expect(User::withTrashed()->find($target->id))->not->toBeNull();
    expect(User::withTrashed()->find($target->id)->trashed())->toBeTrue();
});

test('permanent delete only reaches already-deleted accounts', function () {
    $super = usersActingSuper();
    $live = User::factory()->create();

    $this->actingAs($super)
        ->delete(SUPER_USERS_BASE."/super/users/{$live->id}/force")
        ->assertNotFound();

    expect(User::find($live->id))->not->toBeNull();
});

test('non-super users are refused on every users route', function (string $method, string $path) {
    $admin = User::factory()->admin()->create();
    $restaurant = Restaurant::factory()->create();
    $admin->restaurants()->attach($restaurant, ['role' => RestaurantRole::Admin->value]);
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->call($method, SUPER_USERS_BASE.str_replace('{id}', (string) $target->id, $path))
        ->assertForbidden();

    expect(User::find($target->id))->not->toBeNull();
})->with([
    'index' => ['GET', '/super/users'],
    'show' => ['GET', '/super/users/{id}'],
    'destroy' => ['DELETE', '/super/users/{id}'],
    'restore' => ['POST', '/super/users/{id}/restore'],
    'force' => ['DELETE', '/super/users/{id}/force'],
]);
