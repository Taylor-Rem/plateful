<?php

use App\Enums\DeliveryMode;
use App\Enums\OrderType;
use App\Enums\SelfDeliveryTipRecipient;
use App\Enums\TipRecipient;
use App\Models\Restaurant;

function tipRestaurant(array $attrs = []): Restaurant
{
    $r = new Restaurant;
    $r->forceFill(array_merge([
        'name' => 'Test',
        'subdomain' => 'test',
        'email' => 'x@x.test',
        'street' => '1',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
        'delivery_enabled' => true,
        'delivery_mode' => null,
        'self_delivery_tip_recipient' => SelfDeliveryTipRecipient::Driver,
    ], $attrs));

    return $r;
}

test('pickup always goes to the restaurant pool', function () {
    $r = tipRestaurant(['delivery_mode' => DeliveryMode::ThirdParty]);

    expect(TipRecipient::forOrder($r, OrderType::Pickup))->toBe(TipRecipient::Pool);
});

test('third-party delivery sends tip to the driver', function () {
    $r = tipRestaurant(['delivery_mode' => DeliveryMode::ThirdParty]);

    expect(TipRecipient::forOrder($r, OrderType::Delivery))->toBe(TipRecipient::Driver);
});

test('self-delivery honors the self_delivery_tip_recipient setting', function () {
    $driver = tipRestaurant([
        'delivery_mode' => DeliveryMode::SelfDelivery,
        'self_delivery_tip_recipient' => SelfDeliveryTipRecipient::Driver,
    ]);
    $pool = tipRestaurant([
        'delivery_mode' => DeliveryMode::SelfDelivery,
        'self_delivery_tip_recipient' => SelfDeliveryTipRecipient::Pool,
    ]);
    $split = tipRestaurant([
        'delivery_mode' => DeliveryMode::SelfDelivery,
        'self_delivery_tip_recipient' => SelfDeliveryTipRecipient::Split5050,
    ]);

    expect(TipRecipient::forOrder($driver, OrderType::Delivery))->toBe(TipRecipient::Driver)
        ->and(TipRecipient::forOrder($pool, OrderType::Delivery))->toBe(TipRecipient::Pool)
        ->and(TipRecipient::forOrder($split, OrderType::Delivery))->toBe(TipRecipient::Split);
});

test('delivery with no mode configured defaults to driver', function () {
    $r = tipRestaurant(['delivery_mode' => null]);

    expect(TipRecipient::forOrder($r, OrderType::Delivery))->toBe(TipRecipient::Driver);
});
