<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\CheckoutIntentData;
use App\Data\OrderData;
use App\Data\OrderPlacedData;
use App\Enums\DeliveryMode;
use App\Enums\OrderType;
use App\Enums\PaymentState;
use App\Exceptions\InvalidCheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutIntentRequest;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PendingCheckout;
use App\Models\Restaurant;
use App\Services\CartManager;
use App\Services\OrderPlacement;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App checkout. Where the web hands the customer to a Stripe-hosted page,
 * the app confirms a PaymentIntent on-device, so payment is two steps:
 *
 *   1. `intents`  — validate + snapshot the cart exactly as the web does,
 *                   create the intent on the connected account, return the
 *                   client secret.
 *   2. `confirm`  — after PaymentSheet succeeds, materialise the order. The
 *                   webhook does the same independently; both are idempotent
 *                   on the intent id.
 */
class CheckoutController extends Controller
{
    public function intents(
        CheckoutIntentRequest $request,
        Restaurant $restaurant,
        CartManager $manager,
        OrderPlacement $placement,
        StripeConnectService $connect,
    ): JsonResponse {
        if (! $restaurant->isStripeReady()) {
            throw InvalidCheckoutException::withErrors([
                'payment' => 'This restaurant can’t take payments right now. Please try again later.',
            ]);
        }

        $publishableKey = (string) config('services.stripe.key', '');
        abort_if($publishableKey === '', 503, 'Payments are not configured.');

        $cart = $manager->current();
        $snapshot = $placement->prepare($cart, $restaurant, $request->validated(), $request->user());

        $pending = PendingCheckout::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()?->id,
            'payload' => $snapshot,
            'status' => PendingCheckout::STATUS_AWAITING,
        ]);

        // Same rule as the web: hold rather than charge when fulfilment
        // depends on a courier nobody has found yet.
        $manualCapture = OrderType::from($snapshot['type']) === OrderType::Delivery
            && $restaurant->delivery_mode !== DeliveryMode::SelfDelivery;

        $intent = $connect->createPaymentIntent(
            $restaurant,
            (int) $snapshot['total_cents'],
            (int) $snapshot['application_fee_cents'],
            (string) $snapshot['customer_email'],
            idempotencyKey: 'pending_checkout_'.$pending->id,
            pendingCheckoutId: $pending->id,
            manualCapture: $manualCapture,
        );

        $pending->update(['stripe_payment_intent_id' => $intent->id]);

        return response()->json([
            'data' => new CheckoutIntentData(
                pendingCheckoutId: $pending->id,
                paymentIntentId: $intent->id,
                clientSecret: (string) $intent->client_secret,
                publishableKey: $publishableKey,
                stripeAccountId: (string) $restaurant->stripe_account_id,
                manualCapture: $manualCapture,
                subtotalCents: (int) $snapshot['subtotal_cents'],
                taxCents: (int) $snapshot['tax_cents'],
                deliveryFeeCents: (int) $snapshot['delivery_fee_cents'],
                tipCents: (int) $snapshot['tip_cents'],
                totalCents: (int) $snapshot['total_cents'],
            ),
        ], 201);
    }

    public function confirm(
        Request $request,
        Restaurant $restaurant,
        PendingCheckout $pendingCheckout,
        CartManager $manager,
        OrderPlacement $placement,
        StripeConnectService $connect,
    ): JsonResponse {
        abort_unless(
            $pendingCheckout->restaurant_id === $restaurant->id
                && $pendingCheckout->stripe_payment_intent_id !== null
                && $this->requesterOwns($request, $pendingCheckout, $manager),
            404,
        );

        $intentId = (string) $pendingCheckout->stripe_payment_intent_id;

        if ($pendingCheckout->status === PendingCheckout::STATUS_CONSUMED) {
            $order = Order::withoutTenantScope()->where('stripe_payment_intent_id', $intentId)->first();

            if ($order !== null) {
                return $this->placedResponse($order);
            }
        }

        $intent = $connect->retrievePaymentIntent($restaurant, $intentId);

        $paymentState = match ($intent->status ?? null) {
            'succeeded' => PaymentState::Captured,
            'requires_capture' => PaymentState::Authorized,
            default => null,
        };

        if ($paymentState === null) {
            return response()->json([
                'message' => 'Your payment hasn’t completed yet.',
                'paymentStatus' => (string) ($intent->status ?? 'unknown'),
            ], 409);
        }

        $order = $placement->completeCheckout($pendingCheckout, [
            'stripe_payment_intent_id' => $intentId,
            'payment_state' => $paymentState,
        ]);

        return $this->placedResponse($order, 201);
    }

    /**
     * A pending checkout is readable by the customer who started it: the
     * signed-in user it was created for, or the guest holding its cart's
     * token. Anyone else gets the same 404 as a missing id.
     */
    protected function requesterOwns(Request $request, PendingCheckout $pending, CartManager $manager): bool
    {
        $user = $request->user();

        if ($pending->user_id !== null) {
            return $user !== null && $user->id === $pending->user_id;
        }

        $token = $manager->tokenFromRequest();
        $cartId = $pending->payload['cart_id'] ?? null;

        if ($token === null || $cartId === null) {
            return false;
        }

        $cart = Cart::query()->find($cartId);

        return $cart !== null && $cart->token !== null && hash_equals((string) $cart->token, $token);
    }

    protected function placedResponse(Order $order, int $status = 200): JsonResponse
    {
        $order->loadMissing(['items', 'deliveryAssignment']);

        return response()->json([
            'data' => new OrderPlacedData(
                order: OrderData::fromModel($order),
                confirmationToken: (string) $order->confirmation_token,
            ),
        ], $status);
    }
}
