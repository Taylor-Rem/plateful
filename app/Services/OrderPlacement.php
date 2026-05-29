<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TipRecipient;
use App\Exceptions\InvalidCheckoutException;
use App\Mail\OrderConfirmationToCustomer;
use App\Mail\OrderNotificationToRestaurant;
use App\Models\Address;
use App\Models\Cart;
use App\Models\ItemTemplateOption;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderPlacement
{
    public function __construct(protected CartManager $carts) {}

    /**
     * Place an order from the given cart.
     *
     * @param  array<string, mixed>  $data
     */
    public function place(Cart $cart, Restaurant $restaurant, array $data, ?User $user): Order
    {
        $cart->loadMissing(['items.menuItem.template.groups.options']);

        if (! $restaurant->isOpenAt()) {
            $label = $restaurant->formatNextOpenAt();
            $message = $restaurant->name.' is currently closed.'
                .($label ? " {$label}." : '');
            throw InvalidCheckoutException::withErrors([
                'restaurant_closed' => $message,
            ]);
        }

        if ($cart->items->isEmpty()) {
            throw InvalidCheckoutException::withErrors([
                'cart' => 'Your cart is empty.',
            ]);
        }

        $this->validateCartLines($cart);

        $subtotalCents = 0;
        foreach ($cart->items as $line) {
            $subtotalCents += (int) $line->unit_price_cents * (int) $line->quantity;
        }

        $type = OrderType::from($data['type']);
        $taxRate = (float) $restaurant->tax_rate_percent;
        $taxCents = (int) round($subtotalCents * $taxRate / 100);
        $deliveryFeeCents = $type === OrderType::Delivery ? (int) $restaurant->delivery_fee_cents : 0;
        $tipCents = max(0, (int) ($data['tip_cents'] ?? 0));
        $tipRecipient = TipRecipient::forOrder($restaurant, $type);
        $totalCents = $subtotalCents + $taxCents + $deliveryFeeCents + $tipCents;

        $deliveryAddress = null;
        if ($type === OrderType::Delivery) {
            $addr = $data['delivery_address'] ?? [];
            $deliveryAddress = [
                'street' => (string) ($addr['street'] ?? ''),
                'street2' => isset($addr['street2']) ? (string) $addr['street2'] : null,
                'city' => (string) ($addr['city'] ?? ''),
                'state' => (string) ($addr['state'] ?? ''),
                'postal_code' => (string) ($addr['postal_code'] ?? ''),
                'country' => (string) ($addr['country'] ?? 'US'),
                'instructions' => isset($addr['instructions']) ? (string) $addr['instructions'] : null,
            ];
        }

        $confirmationToken = Str::random(64);

        $order = DB::transaction(function () use (
            $cart, $restaurant, $user, $data, $type,
            $subtotalCents, $taxCents, $deliveryFeeCents, $tipCents, $tipRecipient, $totalCents,
            $deliveryAddress, $confirmationToken
        ) {
            $order = $this->createOrderWithUniqueNumber(
                $restaurant,
                $user,
                $data,
                $type,
                $subtotalCents,
                $taxCents,
                $deliveryFeeCents,
                $tipCents,
                $tipRecipient,
                $totalCents,
                $deliveryAddress,
                $confirmationToken,
            );

            OrderEvent::create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Pending->value,
                'occurred_at' => $order->placed_at ?? now(),
                'user_id' => $user?->id,
                'note' => null,
            ]);

            foreach ($cart->items as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $line->menu_item_id,
                    'name' => $line->menuItem?->name ?? 'Item',
                    'unit_price_cents' => (int) $line->unit_price_cents,
                    'quantity' => (int) $line->quantity,
                    'modifiers' => $line->modifiers,
                    'subtotal_cents' => (int) $line->unit_price_cents * (int) $line->quantity,
                ]);
            }

            if ($user) {
                $this->upsertRestaurantCustomer($user, $restaurant, $order);
            }

            if ($user && ! empty($data['save_address']) && $type === OrderType::Delivery && $deliveryAddress) {
                try {
                    Address::create([
                        'user_id' => $user->id,
                        'street' => $deliveryAddress['street'],
                        'street2' => $deliveryAddress['street2'],
                        'city' => $deliveryAddress['city'],
                        'state' => $deliveryAddress['state'],
                        'postal_code' => $deliveryAddress['postal_code'],
                        'country' => $deliveryAddress['country'] ?: 'US',
                        'instructions' => $deliveryAddress['instructions'],
                        'is_default' => false,
                    ]);
                } catch (\Throwable $e) {
                    // best-effort
                }
            }

            $cart->items()->delete();

            return $order;
        });

        $order->load(['items', 'restaurant']);

        Mail::to($order->customer_email)->queue(new OrderConfirmationToCustomer($order));

        if (! empty($restaurant->email)) {
            Mail::to($restaurant->email)->queue(new OrderNotificationToRestaurant($order));
        }

        return $order;
    }

    protected function validateCartLines(Cart $cart): void
    {
        $errors = [];

        foreach ($cart->items as $idx => $line) {
            $menuItem = $line->menuItem;

            if (! $menuItem || ! $menuItem->is_available) {
                $errors["items.$idx"][] = ($menuItem?->name ?? 'Item').' is no longer available.';

                continue;
            }

            $modifiers = $line->modifiers;
            $hasModifiers = is_array($modifiers) && isset($modifiers['groups']);

            // Modifiers were captured but the item no longer has a template —
            // the configurator has been removed since cart-add. Stale data.
            if ($hasModifiers && $menuItem->item_template_id === null) {
                $errors["items.$idx"][] = '"'.$menuItem->name.'" no longer has the customization options you selected. Please re-add it.';

                continue;
            }

            if (! $hasModifiers) {
                continue;
            }

            $optionIds = [];
            foreach ($modifiers['groups'] as $g) {
                foreach ($g['selections'] ?? [] as $sel) {
                    if (isset($sel['option_id'])) {
                        $optionIds[] = (int) $sel['option_id'];
                    }
                }
            }

            if ($optionIds === []) {
                continue;
            }

            $options = ItemTemplateOption::query()
                ->whereIn('id', $optionIds)
                ->get()
                ->keyBy('id');

            // Build the option-id → group-id map for the *current* template.
            $optionToGroup = [];
            $groups = $menuItem->template?->groups ?? collect();
            foreach ($groups as $group) {
                foreach ($group->options as $opt) {
                    $optionToGroup[$opt->id] = $group;
                }
            }

            $invalidOption = false;
            foreach ($optionIds as $oid) {
                if (! isset($options[$oid]) || ! isset($optionToGroup[$oid])) {
                    $errors["items.$idx"][] = 'A previously selected option for "'.$menuItem->name.'" is no longer available.';
                    $invalidOption = true;
                    break;
                }
                if (! (bool) $options[$oid]->is_available) {
                    $errors["items.$idx"][] = 'A selected option for "'.$menuItem->name.'" is no longer available.';
                    $invalidOption = true;
                    break;
                }
            }

            if ($invalidOption) {
                continue;
            }

            // Per-group min/max may have tightened since the cart was built.
            foreach ($groups as $group) {
                $countInGroup = 0;
                foreach ($optionIds as $oid) {
                    if (isset($optionToGroup[$oid]) && $optionToGroup[$oid]->id === $group->id) {
                        $countInGroup++;
                    }
                }

                $min = (int) ($group->min_selections ?? 0);
                $max = $group->max_selections === null ? null : (int) $group->max_selections;

                if ($countInGroup < $min || ($max !== null && $countInGroup > $max)) {
                    $errors["items.$idx"][] = '"'.$menuItem->name.'" needs to be reconfigured — its options have changed since you added it.';

                    continue 2;
                }
            }

            // Defence-in-depth: recompute the unit price from the current
            // template + selections and reject if the cart's stored price has
            // gone stale (option deltas changed after cart-add).
            $expectedPriceCents = $menuItem->priceForSelectionsCents($optionIds);
            if ($expectedPriceCents !== (int) $line->unit_price_cents) {
                $errors["items.$idx"][] = 'The price of "'.$menuItem->name.'" has changed since you added it. Please re-add it to checkout.';
            }
        }

        if ($errors !== []) {
            throw InvalidCheckoutException::withErrors($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $deliveryAddress
     */
    protected function createOrderWithUniqueNumber(
        Restaurant $restaurant,
        ?User $user,
        array $data,
        OrderType $type,
        int $subtotalCents,
        int $taxCents,
        int $deliveryFeeCents,
        int $tipCents,
        TipRecipient $tipRecipient,
        int $totalCents,
        ?array $deliveryAddress,
        string $confirmationToken,
    ): Order {
        $addressId = null;
        if ($user && isset($data['address_id'])) {
            $candidate = Address::query()
                ->where('id', (int) $data['address_id'])
                ->where('user_id', $user->id)
                ->first();
            if ($candidate) {
                $addressId = $candidate->id;
            }
        }

        $attempts = 0;
        while (true) {
            $attempts++;
            $number = Order::generateNumber($restaurant);
            try {
                return Order::create([
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $user?->id,
                    'address_id' => $addressId,
                    'customer_name' => (string) $data['customer_name'],
                    'customer_email' => (string) $data['customer_email'],
                    'customer_phone' => isset($data['customer_phone']) ? (string) $data['customer_phone'] : null,
                    'delivery_address' => $deliveryAddress,
                    'number' => $number,
                    'status' => OrderStatus::Pending,
                    'type' => $type,
                    'subtotal_cents' => $subtotalCents,
                    'tax_cents' => $taxCents,
                    'tip_cents' => $tipCents,
                    'tip_recipient' => $tipRecipient,
                    'delivery_fee_cents' => $deliveryFeeCents,
                    'application_fee_cents' => 0,
                    'total_cents' => $totalCents,
                    'notes' => $data['notes'] ?? null,
                    'placed_at' => now(),
                    'confirmation_token' => $confirmationToken,
                ]);
            } catch (QueryException $e) {
                if ($attempts >= 10) {
                    throw $e;
                }
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Maintain the restaurant_customer pivot counters when an order is placed
     * by an authenticated user. Decision D: denormalized counters.
     */
    protected function upsertRestaurantCustomer(User $user, Restaurant $restaurant, Order $order): void
    {
        $pivot = RestaurantCustomer::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->lockForUpdate()
            ->first();

        $now = now();

        if ($pivot) {
            $pivot->first_ordered_at ??= $now;
            $pivot->last_ordered_at = $now;
            $pivot->total_orders = (int) $pivot->total_orders + 1;
            $pivot->total_spent_cents = (int) $pivot->total_spent_cents + (int) $order->total_cents;
            $pivot->save();

            return;
        }

        RestaurantCustomer::create([
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'first_ordered_at' => $now,
            'last_ordered_at' => $now,
            'total_orders' => 1,
            'total_spent_cents' => (int) $order->total_cents,
        ]);
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains((string) $e->getMessage(), 'orders_number_unique')
            || str_contains((string) $e->getMessage(), 'UNIQUE constraint');
    }
}
