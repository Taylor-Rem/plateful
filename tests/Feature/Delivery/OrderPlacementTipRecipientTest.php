<?php

use App\Enums\DeliveryMode;
use App\Enums\OrderType;
use App\Enums\SelfDeliveryTipRecipient;
use App\Enums\TipRecipient;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Services\OrderPlacement;
use App\Tenancy\CurrentTenant;

require_once __DIR__.'/DeliveryQuoteTestHelpers.php';

function placementRestaurant(string $sub, array $overrides = []): Restaurant
{
    $r = Restaurant::create(array_merge([
        'name' => "R-{$sub}",
        'subdomain' => $sub,
        'email' => "{$sub}@x.test",
        'street' => '1', 'city' => 'NYC', 'state' => 'NY', 'postal_code' => '10001',
    ], $overrides));

    app(CurrentTenant::class)->set($r);

    return $r;
}

function placementCart(Restaurant $r): Cart
{
    $cat = MenuCategory::create([
        'restaurant_id' => $r->id, 'name' => 'C', 'slug' => 'c', 'position' => 0, 'is_active' => true,
    ]);
    $item = MenuItem::create([
        'restaurant_id' => $r->id, 'menu_category_id' => $cat->id, 'item_template_id' => null,
        'name' => 'Soda', 'slug' => 'soda', 'price_cents' => 1000, 'is_available' => true, 'position' => 0,
    ]);

    $cart = Cart::create([
        'restaurant_id' => $r->id,
        'token' => bin2hex(random_bytes(8)),
    ]);
    CartItem::create([
        'cart_id' => $cart->id,
        'menu_item_id' => $item->id,
        'unit_price_cents' => 1000,
        'quantity' => 1,
        'modifiers' => null,
    ]);

    return $cart->fresh()->load('items.menuItem.template.groups.options');
}

function placeWith(Restaurant $r, OrderType $type, ?string $quoteToken = null)
{
    $cart = placementCart($r);

    $data = [
        'type' => $type->value,
        'tip_cents' => 500,
        'customer_name' => 'Alice',
        'customer_email' => 'alice@x.test',
        'customer_phone' => '555',
        'notes' => null,
        'delivery_address' => $type === OrderType::Delivery ? [
            'street' => '1', 'city' => 'NYC', 'state' => 'NY', 'postal_code' => '10001', 'country' => 'US',
        ] : null,
        'delivery_quote_token' => $quoteToken,
    ];

    return app(OrderPlacement::class)->place($cart, $r, $data, null);
}

it('writes tip_recipient=pool for pickup orders', function () {
    $r = placementRestaurant('pickupx');

    $order = placeWith($r, OrderType::Pickup);

    expect($order->tip_recipient)->toBe(TipRecipient::Pool);
});

it('writes tip_recipient=driver for third-party delivery orders', function () {
    $r = placementRestaurant('tpx', [
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty->value,
    ]);

    // Third-party delivery now requires a priced quote — no customer is charged
    // for a delivery nobody quoted.
    $quote = makeDeliveryQuote($r, [
        'street' => '1', 'city' => 'NYC', 'state' => 'NY', 'postal_code' => '10001', 'country' => 'US',
    ]);

    $order = placeWith($r, OrderType::Delivery, $quote->token);

    expect($order->tip_recipient)->toBe(TipRecipient::Driver);
});

it('writes tip_recipient=split for self-delivery with split_50_50 setting', function () {
    $r = placementRestaurant('splitx', [
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery->value,
        'self_delivery_tip_recipient' => SelfDeliveryTipRecipient::Split5050->value,
    ]);

    $order = placeWith($r, OrderType::Delivery);

    expect($order->tip_recipient)->toBe(TipRecipient::Split);
});

it('writes tip_recipient=pool for self-delivery with pool setting', function () {
    $r = placementRestaurant('selfpoolx', [
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery->value,
        'self_delivery_tip_recipient' => SelfDeliveryTipRecipient::Pool->value,
    ]);

    $order = placeWith($r, OrderType::Delivery);

    expect($order->tip_recipient)->toBe(TipRecipient::Pool);
});
