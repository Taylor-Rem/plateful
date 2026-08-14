<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SavingsCalculatorController extends Controller
{
    /**
     * Stripe's fixed per-charge fee in cents. Deliberately a constant, not
     * config: `platform.stripe_variable_rate` exists because the delivery
     * gross-up needs a seam, but nothing else in the app prices the fixed 30¢
     * and the marketing math shouldn't invent an env var for it.
     */
    private const STRIPE_FIXED_FEE_CENTS = 30;

    /**
     * Public savings calculator (marketing site, root domain).
     *
     * All math happens client-side; the server's job is to hand the page the
     * real platform numbers so marketing copy can never drift from pricing
     * config. Rates ride in as props, not hardcoded page values.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Savings', [
            'authUserName' => $user?->name,
            'hasAdminAccess' => (bool) $user?->isAdmin(),
            'adminUrl' => $request->getScheme().'://admin.'.config('platform.primary_domain'),
            'feePercent' => (float) config('platform.default_application_fee_percent'),
            'feeCapCents' => (int) config('platform.commission_monthly_cap_cents'),
            'stripeVariableRate' => (float) config('platform.stripe_variable_rate'),
            'stripeFixedFeeCents' => self::STRIPE_FIXED_FEE_CENTS,
            'bookingUrl' => config('platform.booking_url'),
        ]);
    }
}
