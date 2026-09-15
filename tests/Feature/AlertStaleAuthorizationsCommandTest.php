<?php

use App\Enums\PaymentState;
use App\Exceptions\StaleAuthorizationsDetected;
use App\Models\Order;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;

it('is quiet when no order has been authorized for too long', function () {
    Exceptions::fake();
    Order::factory()->create([
        'payment_state' => PaymentState::Authorized,
        'authorized_at' => now()->subMinutes(5),
    ]);
    Order::factory()->create([
        'payment_state' => PaymentState::Captured,
        'authorized_at' => now()->subHours(5),
        'captured_at' => now()->subHours(5),
    ]);

    $this->artisan('orders:alert-stale-authorizations')
        ->expectsOutputToContain('No orders authorized for more than 60 minutes.')
        ->assertSuccessful();

    Exceptions::assertNotReported(StaleAuthorizationsDetected::class);
});

it('reports orders left authorized past the deadline so Sentry can alert', function () {
    Exceptions::fake();
    $stranded = Order::factory()->create([
        'payment_state' => PaymentState::Authorized,
        'authorized_at' => now()->subHours(3),
    ]);
    Order::factory()->create([
        'payment_state' => PaymentState::Authorized,
        'authorized_at' => now()->subMinutes(10),
    ]);

    $this->artisan('orders:alert-stale-authorizations')
        ->expectsOutputToContain("order {$stranded->id}")
        ->expectsOutputToContain('1 order(s) still authorized after 60 minutes')
        ->assertFailed();

    Exceptions::assertReported(fn (StaleAuthorizationsDetected $e): bool => $e->count === 1
        && $e->olderThanMinutes === 60
        && $e->orderIds === [$stranded->id]);
});

it('accepts a custom threshold', function () {
    Exceptions::fake();
    Order::factory()->create([
        'payment_state' => PaymentState::Authorized,
        'authorized_at' => now()->subMinutes(20),
    ]);

    $this->artisan('orders:alert-stale-authorizations', ['--older-than' => 15])
        ->assertFailed();

    Exceptions::assertReported(fn (StaleAuthorizationsDetected $e): bool => $e->olderThanMinutes === 15);
});

it('runs hourly on the scheduler', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'orders:alert-stale-authorizations'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
