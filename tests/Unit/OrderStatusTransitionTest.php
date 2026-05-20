<?php

use App\Enums\OrderStatus;

test('legal transitions return true', function () {
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Confirmed))->toBeTrue();
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled))->toBeTrue();
    expect(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Preparing))->toBeTrue();
    expect(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Cancelled))->toBeTrue();
    expect(OrderStatus::Preparing->canTransitionTo(OrderStatus::Ready))->toBeTrue();
    expect(OrderStatus::Preparing->canTransitionTo(OrderStatus::Cancelled))->toBeTrue();
    expect(OrderStatus::Ready->canTransitionTo(OrderStatus::Completed))->toBeTrue();
    expect(OrderStatus::Ready->canTransitionTo(OrderStatus::Cancelled))->toBeTrue();
});

test('illegal transitions return false', function () {
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Preparing))->toBeFalse();
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Ready))->toBeFalse();
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Completed))->toBeFalse();
    expect(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Ready))->toBeFalse();
    expect(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Completed))->toBeFalse();
    expect(OrderStatus::Preparing->canTransitionTo(OrderStatus::Completed))->toBeFalse();
    expect(OrderStatus::Ready->canTransitionTo(OrderStatus::Preparing))->toBeFalse();
    expect(OrderStatus::Ready->canTransitionTo(OrderStatus::Pending))->toBeFalse();
});

test('terminal statuses cannot transition anywhere', function () {
    foreach (OrderStatus::cases() as $target) {
        expect(OrderStatus::Completed->canTransitionTo($target))->toBeFalse();
        expect(OrderStatus::Cancelled->canTransitionTo($target))->toBeFalse();
    }
});
