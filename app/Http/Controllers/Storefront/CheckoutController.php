<?php

namespace App\Http\Controllers\Storefront;

use App\Data\AddressData;
use App\Data\CartData;
use App\Data\RestaurantData;
use App\Enums\DeliveryMode;
use App\Enums\OrderType;
use App\Enums\PaymentState;
use App\Exceptions\InvalidCheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Models\PendingCheckout;
use App\Models\Restaurant;
use App\Services\CartManager;
use App\Services\OrderPlacement;
use App\Services\Stripe\StripeConnectService;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Checkout\Session;
use Throwable;

class CheckoutController extends Controller
{
    public const RECENT_ORDER_COOKIE = 'plateful_recent_order';

    public function show(CurrentTenant $tenant, CartManager $manager): Response|RedirectResponse
    {
        $cart = $manager->current();

        if (! $cart || $cart->items()->count() === 0) {
            return redirect()->route('storefront.home')
                ->with('error', 'Your cart is empty.');
        }

        $restaurant = $tenant->get();
        $user = request()->user();

        $savedAddresses = [];
        if ($user) {
            $savedAddresses = $user->addresses()->orderByDesc('is_default')->orderByDesc('id')->get()
                ->map(fn ($a) => AddressData::fromModel($a))
                ->all();
        }

        return Inertia::render('Storefront/Checkout', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'cart' => CartData::fromModel($cart),
            'savedAddresses' => $savedAddresses,
            'tipPresets' => [0, 15, 18, 20],
            'brand' => BrandColors::paletteFor(
                $restaurant->primary_color,
                $restaurant->secondary_color,
            ),
        ]);
    }

    /**
     * Validate the cart, snapshot it, and redirect the customer to a
     * Stripe-hosted Checkout Session on the restaurant's connected account.
     * The order is NOT created here — it materializes once payment succeeds
     * (see paymentReturn() + the checkout.session.completed webhook).
     */
    public function store(
        CheckoutRequest $request,
        CurrentTenant $tenant,
        CartManager $manager,
        OrderPlacement $placement,
        StripeConnectService $connect,
    ): \Symfony\Component\HttpFoundation\Response {
        $cart = $manager->current();
        $restaurant = $tenant->get();

        if (! $restaurant->isStripeReady()) {
            throw InvalidCheckoutException::withErrors([
                'payment' => 'This restaurant can’t take payments right now. Please try again later.',
            ]);
        }

        $snapshot = $placement->prepare($cart, $restaurant, $request->validated(), $request->user());

        $pending = PendingCheckout::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()?->id,
            'payload' => $snapshot,
            'status' => PendingCheckout::STATUS_AWAITING,
        ]);

        // Hold rather than charge when fulfilment depends on a courier nobody
        // has found yet. Uber only searches once the delivery is CREATED, which
        // is after payment — so this is the only mechanism that can keep the
        // promise that nobody pays for a delivery that didn't happen.
        $manualCapture = OrderType::from($snapshot['type']) === OrderType::Delivery
            && $restaurant->delivery_mode !== DeliveryMode::SelfDelivery;

        $session = $connect->createCheckoutSession(
            $restaurant,
            (int) $snapshot['total_cents'],
            (int) $snapshot['application_fee_cents'],
            (string) $snapshot['customer_email'],
            [
                'success_url' => route('storefront.checkout.return').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('storefront.checkout.show'),
            ],
            idempotencyKey: 'pending_checkout_'.$pending->id,
            pendingCheckoutId: $pending->id,
            manualCapture: $manualCapture,
        );

        $pending->update(['stripe_checkout_session_id' => $session->id]);

        // Inertia::location issues a 409 + X-Inertia-Location for Inertia
        // (XHR) requests so the browser does a full navigation to Stripe; a
        // plain 302 otherwise.
        return Inertia::location($session->url);
    }

    /**
     * Stripe redirects the customer here after a (successful or abandoned)
     * payment. Materialize the order eagerly so the customer sees it without
     * waiting on the webhook; both paths are idempotent.
     */
    public function paymentReturn(
        Request $request,
        CurrentTenant $tenant,
        OrderPlacement $placement,
        StripeConnectService $connect,
        CookieJar $cookies,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $sessionId = (string) $request->query('session_id', '');

        $pending = PendingCheckout::query()
            ->where('stripe_checkout_session_id', $sessionId)
            ->where('restaurant_id', $restaurant->id)
            ->first();

        if (! $pending) {
            return redirect()->route('storefront.home')
                ->with('error', 'We couldn’t find that checkout.');
        }

        $session = $connect->retrieveCheckoutSession($restaurant, $sessionId);
        $paymentIntentId = is_string($session->payment_intent) ? $session->payment_intent : null;

        $paymentState = $this->resolvePaymentState($connect, $restaurant, $session, $paymentIntentId);

        if ($paymentState === null) {
            return redirect()->route('storefront.checkout.show')
                ->with('error', 'Your payment wasn’t completed. Your cart is still here.');
        }

        $order = $placement->completeCheckout($pending, [
            'stripe_checkout_session_id' => $sessionId,
            'stripe_payment_intent_id' => $paymentIntentId,
            'payment_state' => $paymentState,
        ]);

        $cookies->queue($cookies->make(
            name: self::RECENT_ORDER_COOKIE,
            value: $order->confirmation_token,
            minutes: 60,
            path: '/',
            domain: null,
            secure: app()->environment('production'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

        return redirect()
            ->route('storefront.orders.show', ['number' => $order->number])
            ->with('success', 'Order placed!');
    }

    /**
     * Decide whether this session actually represents money we can rely on, and
     * in what state.
     *
     * `payment_status === 'paid'` used to be the whole test, but a manual-capture
     * session completes as **`unpaid`** — a successful authorization looks
     * identical to an abandoned checkout by that measure alone, so the old check
     * would have bounced a customer who had just paid.
     *
     * Rather than infer from the session, ask the PaymentIntent: `requires_capture`
     * is unambiguous, and it costs one API call on a path that already makes one.
     *
     * @return PaymentState|null null when the customer never completed payment
     */
    private function resolvePaymentState(
        StripeConnectService $connect,
        Restaurant $restaurant,
        Session $session,
        ?string $paymentIntentId,
    ): ?PaymentState {
        if (($session->payment_status ?? null) === 'paid') {
            return PaymentState::Captured;
        }

        // Anything other than a completed session is an abandoned or expired
        // checkout, whatever the payment_status says.
        if (($session->status ?? null) !== 'complete' || $paymentIntentId === null) {
            return null;
        }

        try {
            $intent = $connect->retrievePaymentIntent($restaurant, $paymentIntentId);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return ($intent->status ?? null) === 'requires_capture'
            ? PaymentState::Authorized
            : null;
    }
}
