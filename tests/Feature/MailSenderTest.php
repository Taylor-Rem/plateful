<?php

use App\Enums\RestaurantRole;
use App\Mail\AdminInvitationMail;
use App\Mail\OrderCancelledToCustomer;
use App\Mail\OrderConfirmationToCustomer;
use App\Mail\OrderNotificationToRestaurant;
use App\Mail\OrderReadyForPickupToCustomer;
use App\Models\AdminInvitation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'mail.senders.orders' => 'orders@plateful.test',
        'mail.senders.service' => 'service@plateful.test',
    ]);
});

test('order emails send from the orders alias', function (string $mailableClass) {
    $order = Order::factory()->create();

    $mailable = $mailableClass === OrderCancelledToCustomer::class
        ? new $mailableClass($order, 'Out of dough')
        : new $mailableClass($order);

    $mailable->assertFrom('orders@plateful.test');
})->with([
    OrderConfirmationToCustomer::class,
    OrderCancelledToCustomer::class,
    OrderReadyForPickupToCustomer::class,
    OrderNotificationToRestaurant::class,
]);

test('admin invitation sends from the service alias', function () {
    $invitation = AdminInvitation::create([
        'email' => 'new-admin@example.test',
        'role' => RestaurantRole::Admin,
        'as_super_admin' => true,
        'token' => AdminInvitation::generateToken(),
        'invited_by_user_id' => User::factory()->create()->id,
        'expires_at' => now()->addDays(7),
    ]);

    (new AdminInvitationMail($invitation))->assertFrom('service@plateful.test');
});
