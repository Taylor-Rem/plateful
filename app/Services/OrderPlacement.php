<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TipRecipient;
use App\Exceptions\InvalidCheckoutException;
use App\Jobs\DispatchDeliveryForOrder;
use App\Jobs\PushOrderToPos;
use App\Mail\OrderConfirmationToCustomer;
use App\Mail\OrderNotificationToRestaurant;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ItemTemplateOption;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\PendingCheckout;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use App\Services\Pos\PosDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderPlacement
{
    public function __construct(protected CartManager $carts) {}

    /**
     * Validate a cart + checkout input and build a serializable snapshot of
     * the order to be placed. Does NOT write the order — that happens in
     * {@see materialize()} once payment has succeeded.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(Cart $cart, Restaurant $restaurant, array $data, ?User $user): array
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
        $items = [];
        foreach ($cart->items as $line) {
            $lineSubtotal = (int) $line->unit_price_cents * (int) $line->quantity;
            $subtotalCents += $lineSubtotal;
            $items[] = [
                'menu_item_id' => $line->menu_item_id,
                'name' => $line->menuItem?->name ?? 'Item',
                'unit_price_cents' => (int) $line->unit_price_cents,
                'quantity' => (int) $line->quantity,
                'modifiers' => $line->modifiers,
                'subtotal_cents' => $lineSubtotal,
            ];
        }

        $type = OrderType::from($data['type']);
        $taxRate = (float) $restaurant->tax_rate_percent;
        $taxCents = (int) round($subtotalCents * $taxRate / 100);
        $deliveryFeeCents = $type === OrderType::Delivery ? (int) $restaurant->delivery_fee_cents : 0;
        $tipCents = max(0, (int) ($data['tip_cents'] ?? 0));
        $tipRecipient = TipRecipient::forOrder($restaurant, $type);
        $totalCents = $subtotalCents + $taxCents + $deliveryFeeCents + $tipCents;

        // The application fee is the restaurant's rate (flat 4% default) applied
        // to the FOOD SUBTOTAL only — not tax, tip, or delivery (those are
        // pass-through and don't belong to Plateful).
        $applicationFeeCents = (int) floor($subtotalCents * (float) $restaurant->application_fee_percent / 100);

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

        return [
            'restaurant_id' => $restaurant->id,
            'cart_id' => $cart->id,
            'user_id' => $user?->id,
            'customer_name' => (string) $data['customer_name'],
            'customer_email' => (string) $data['customer_email'],
            'customer_phone' => isset($data['customer_phone']) ? (string) $data['customer_phone'] : null,
            'type' => $type->value,
            'delivery_address' => $deliveryAddress,
            'address_id' => $addressId,
            'save_address' => ! empty($data['save_address']),
            'tip_cents' => $tipCents,
            'tip_recipient' => $tipRecipient->value,
            'subtotal_cents' => $subtotalCents,
            'tax_cents' => $taxCents,
            'delivery_fee_cents' => $deliveryFeeCents,
            'application_fee_cents' => $applicationFeeCents,
            'total_cents' => $totalCents,
            'confirmation_token' => Str::random(64),
            'notes' => $data['notes'] ?? null,
            'items' => $items,
        ];
    }

    /**
     * Place an order synchronously from a cart (no Stripe). Retained for
     * internal/test use; the customer-facing flow uses prepare() +
     * Checkout + completeCheckout().
     *
     * @param  array<string, mixed>  $data
     */
    public function place(Cart $cart, Restaurant $restaurant, array $data, ?User $user): Order
    {
        return $this->materialize($this->prepare($cart, $restaurant, $data, $user), [], $user);
    }

    /**
     * Materialize a paid checkout snapshot into a real Order. Idempotent on
     * the Stripe Checkout Session id — a duplicate webhook + return won't
     * create two orders.
     *
     * @param  array<string, mixed>  $payment  ['stripe_checkout_session_id'?, 'stripe_payment_intent_id'?]
     */
    public function completeCheckout(PendingCheckout $pending, array $payment): Order
    {
        $order = $this->materialize($pending->payload, $payment, $pending->user);

        if ($pending->status !== PendingCheckout::STATUS_CONSUMED) {
            $pending->update(['status' => PendingCheckout::STATUS_CONSUMED]);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $payment
     */
    public function materialize(array $snapshot, array $payment, ?User $user): Order
    {
        $sessionId = $payment['stripe_checkout_session_id'] ?? null;

        if ($sessionId) {
            $existing = Order::withoutTenantScope()
                ->where('stripe_checkout_session_id', $sessionId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $restaurant = Restaurant::query()->findOrFail($snapshot['restaurant_id']);

        $order = DB::transaction(function () use ($snapshot, $payment, $user, $restaurant) {
            $order = $this->createOrderWithUniqueNumber($snapshot, $payment, $restaurant);

            OrderEvent::create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Pending->value,
                'occurred_at' => $order->placed_at ?? now(),
                'user_id' => $user?->id,
                'note' => null,
            ]);

            foreach ($snapshot['items'] as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $line['menu_item_id'],
                    'name' => $line['name'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'quantity' => (int) $line['quantity'],
                    'modifiers' => $line['modifiers'],
                    'subtotal_cents' => (int) $line['subtotal_cents'],
                ]);
            }

            if ($user) {
                $this->upsertRestaurantCustomer($user, $restaurant, $order);
            }

            if ($user && ! empty($snapshot['save_address'])
                && $snapshot['type'] === OrderType::Delivery->value
                && $snapshot['delivery_address']) {
                try {
                    $addr = $snapshot['delivery_address'];
                    Address::create([
                        'user_id' => $user->id,
                        'street' => $addr['street'],
                        'street2' => $addr['street2'] ?? null,
                        'city' => $addr['city'],
                        'state' => $addr['state'],
                        'postal_code' => $addr['postal_code'],
                        'country' => ($addr['country'] ?? 'US') ?: 'US',
                        'instructions' => $addr['instructions'] ?? null,
                        'is_default' => false,
                    ]);
                } catch (\Throwable $e) {
                    // best-effort
                }
            }

            CartItem::query()->where('cart_id', $snapshot['cart_id'])->delete();

            return $order;
        });

        $order->load(['items', 'restaurant']);

        Mail::to($order->customer_email)->queue(new OrderConfirmationToCustomer($order));

        if (! empty($restaurant->email)) {
            Mail::to($restaurant->email)->queue(new OrderNotificationToRestaurant($order));
        }

        $this->queuePostPaymentFulfillment($order);

        return $order;
    }

    /**
     * Queue fulfillment side effects for a freshly paid order: POS injection
     * and delivery dispatch. Runs on both the checkout-return and Stripe
     * webhook paths; the session-id idempotency short-circuit above prevents
     * replays from re-queueing.
     */
    protected function queuePostPaymentFulfillment(Order $order): void
    {
        // A fulfillment failure must never break placement of a paid order —
        // on the sync queue driver the jobs run (and may throw) inline here.
        try {
            if (app(PosDispatcher::class)->shouldPush($order->restaurant)) {
                PushOrderToPos::dispatch($order->id);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to queue POS push', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        try {
            if ($order->type === OrderType::Delivery) {
                DispatchDeliveryForOrder::dispatch($order->id);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to queue delivery dispatch', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
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
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $payment
     */
    protected function createOrderWithUniqueNumber(array $snapshot, array $payment, Restaurant $restaurant): Order
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $number = Order::generateNumber($restaurant);
            try {
                return Order::create([
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $snapshot['user_id'] ?? null,
                    'address_id' => $snapshot['address_id'] ?? null,
                    'customer_name' => (string) $snapshot['customer_name'],
                    'customer_email' => (string) $snapshot['customer_email'],
                    'customer_phone' => $snapshot['customer_phone'] ?? null,
                    'delivery_address' => $snapshot['delivery_address'] ?? null,
                    'number' => $number,
                    'status' => OrderStatus::Pending,
                    'type' => OrderType::from($snapshot['type']),
                    'subtotal_cents' => (int) $snapshot['subtotal_cents'],
                    'tax_cents' => (int) $snapshot['tax_cents'],
                    'tip_cents' => (int) $snapshot['tip_cents'],
                    'tip_recipient' => TipRecipient::from($snapshot['tip_recipient']),
                    'delivery_fee_cents' => (int) $snapshot['delivery_fee_cents'],
                    'application_fee_cents' => (int) $snapshot['application_fee_cents'],
                    'total_cents' => (int) $snapshot['total_cents'],
                    'stripe_payment_intent_id' => $payment['stripe_payment_intent_id'] ?? null,
                    'stripe_checkout_session_id' => $payment['stripe_checkout_session_id'] ?? null,
                    'notes' => $snapshot['notes'] ?? null,
                    'placed_at' => now(),
                    'confirmation_token' => $snapshot['confirmation_token'],
                ]);
            } catch (QueryException $e) {
                // A clash on the unique session id means the order was already
                // created (concurrent webhook + return) — return it.
                if ($this->isSessionViolation($e) && ! empty($payment['stripe_checkout_session_id'])) {
                    return Order::withoutTenantScope()
                        ->where('stripe_checkout_session_id', $payment['stripe_checkout_session_id'])
                        ->firstOrFail();
                }
                if ($attempts >= 10 || ! $this->isUniqueViolation($e)) {
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

    protected function isSessionViolation(QueryException $e): bool
    {
        return str_contains((string) $e->getMessage(), 'stripe_checkout_session_id');
    }
}
