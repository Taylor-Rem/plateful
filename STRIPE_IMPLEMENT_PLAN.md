# Stripe Connect implementation plan

Plan for switching Plateful from a subscription billing model (Cashier-based,
currently shipped) to a pure per-order application-fee model (Stripe Connect
Express, to be built). This document is the authoritative plan; the codebase
still contains the older subscription wiring that Phase 1 removes.

## Pricing decision (locked in)

**Plateful charges 1% per order. No subscription. No tiers. No minimums.**

- Charged via Stripe Connect `application_fee_amount` at order time.
- Fee base is the **food subtotal only** — not tax, not tip, not delivery fee.
  Those are pass-through dollars that don't belong to the restaurant.
- This is a permanent commitment. Future premium add-ons (analytics,
  marketing, integrations) can layer on top, but the 1% base never changes.

Public-facing copy: _"1% per order. That's it. No subscription, no tiers,
no minimums. You only pay when you make money."_

Competitive context on a $25 order:

| Service       | Per-order cost on $25 |
|---------------|-----------------------|
| DoorDash      | $7.50 (30%)           |
| GloriaFood    | $0.38–$0.50           |
| ChowNow       | $0 + $150/mo flat     |
| **Plateful**  | **$0.25**             |

## How money flows

Stripe Connect routes funds atomically — no manual transfers from Plateful.

1. Plateful has its own platform Stripe account (this is your Stripe
   account; bank info on file).
2. Each restaurant has an Express connected account under your platform,
   created during onboarding.
3. A customer pays $25 on the storefront. The charge is created on the
   connected account with `application_fee_amount: 25` (cents).
4. Stripe atomically splits the payment:
   - $0.25 → your platform Stripe account (the 1% application fee)
   - ~$23.72 → restaurant's connected account (after Stripe's $1.03
     processing fee, which the restaurant pays as usual)
5. Both accounts pay out to their respective banks on the normal Stripe
   payout schedule (daily by default). Your bank receives a lump sum of
   that day's application fees across every restaurant.

Express Connect side costs (paid by Plateful, not the restaurant):
- $2/month per active connected account
- 0.25% + $0.25 per payout to the connected account (deducted from the
  restaurant's payout by default)

## IA decision: Connect onboarding lives on the storefront

Stripe Connect setup is part of the "finish setting up your restaurant"
flow, so it lives on the storefront in the onboarding area — same pattern
as the recent in-place editing direction.

The **Payouts view** (Phase 4), on the other hand, is operational data and
goes in the admin console alongside orders/kitchen/team. Split rule:
**lifecycle on the storefront, ongoing operations in the console.**

---

## Phase 1 — Remove subscription wiring

The Cashier subscription layer goes away entirely. Per-restaurant billing
becomes "we deduct 1% at order time," not "we charge a monthly subscription."

- Drop `Billable` trait from `Restaurant`.
- Remove `Cashier::useCustomerModel(...)` from `AppServiceProvider`.
- Remove `BillingController`, the `/{subdomain}/billing` routes, and
  `resources/js/pages/Admin/TenantAdmin/Billing.vue`.
- Remove `app/Console/Commands/SuspendExpiredTrials.php` and its schedule
  entry in `routes/console.php`.
- Remove `config('platform.billing.*')` keys.
- Migration: drop `stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`
  columns from `restaurants`. Drop `subscriptions` and `subscription_items`
  tables.
- `SignupsController::approve()`: remove the `trial_ends_at` assignment.
- `OnboardingController`: drop any billing reference. The existing "Stripe"
  step gets repurposed in Phase 2.
- `composer remove laravel/cashier`. Connect work uses `stripe/stripe-php`
  directly; Cashier doesn't help with Connect.
- Suspension behavior stays — `status = suspended` still 503s the
  storefront — but the trigger is now manual / policy-only. No auto-suspend
  from trial expiry.
- Delete the now-irrelevant tests:
  `tests/Feature/BillingTest.php`,
  `tests/Feature/SuspendExpiredTrialsCommandTest.php`.
- Update `tests/Feature/SignupApprovalTest.php` to drop the
  "trial starts at 14 days" assertion.

Acceptance: suite stays green; no references to `trial`, `subscription`,
or `Cashier` remain in app code; landing page no longer mentions trials.

## Phase 2 — Stripe Connect onboarding (storefront-based, Option B)

`restaurants` already has `stripe_account_id` and `stripe_account_status`
from the original schema — they were never used and are perfect for
Connect.

- New migration: change the `application_fee_percent` default from
  `10.00` to `1.00`. Backfill any existing rows to `1.00`.
- New service `App\Services\Stripe\StripeConnectService`:
  - `createExpressAccount(Restaurant): string` — creates the Stripe
    account, returns `acct_xxx`. Email + business profile prefilled from
    the restaurant.
  - `createAccountLink(Restaurant, returnUrl, refreshUrl): string` —
    generates a Stripe-hosted onboarding URL.
  - `retrieveAccount(string $stripeAccountId): \Stripe\Account` — used to
    read `charges_enabled`, `payouts_enabled`, `details_submitted`.
  - `createDashboardLink(Restaurant): string` — generates Express Dashboard
    login link for the owner to update bank info later.
- New controller `App\Http\Controllers\Storefront\Admin\StripeConnectController`:
  - `start(Restaurant)` — creates the connected account if missing, then
    redirects to a fresh AccountLink URL.
  - `return(Restaurant)` — Stripe redirects here after onboarding; refresh
    `stripe_account_status` from the API, redirect back to onboarding wizard.
  - `refresh(Restaurant)` — Stripe redirects here if the owner refreshes
    mid-flow; just regenerate the link.
  - `dashboard(Restaurant)` — redirects authenticated owner to the Express
    Dashboard for bank-info updates.
- Routes in `routes/storefront.php` under `auth` + the existing storefront
  admin prefix, gated by a new `StripeConnectPolicy` (admin role only).
- Webhook listener for `account.updated` (extend the Cashier webhook
  removal — register a small custom controller since Cashier is gone).
  Syncs `charges_enabled` / `payouts_enabled` / `details_submitted` into
  `stripe_account_status` (string column already exists; pick a small
  vocabulary like `pending` / `restricted` / `enabled`).
- Update `OnboardingController::canGoLive()`: Stripe Connect becomes
  **required**. No payments, no go-live. The Stripe step in the onboarding
  Vue page now shows live status pulled from `stripe_account_status` and a
  prominent "Connect Stripe" button that posts to the `start` action.
- Tests: account creation, account link generation, return handler updates
  status, webhook updates status, can't go live without
  `charges_enabled = true`.

Acceptance: a fresh restaurant can complete Connect onboarding entirely on
the storefront, end up with `charges_enabled = true`, and pass the
onboarding `canGoLive` check.

## Phase 3 — Wire application fee into order placement

- Identify the order placement path. Current entry point is the
  `OrderPlacement` service / `CheckoutController::store`. Trace where the
  PaymentIntent is created (or where the charge is initiated).
- When creating the PaymentIntent for an order, pass:
  - `stripe_account: $restaurant->stripe_account_id` (the charge lives on
    the connected account)
  - `application_fee_amount: (int) floor($foodSubtotalCents * ($restaurant->application_fee_percent / 100))`
- Fee base is **food subtotal only**:
  - **Not** tax (pass-through to government)
  - **Not** tip (goes entirely to restaurant/staff/driver)
  - **Not** delivery fee (pays the driver or covers delivery cost)
- Refunds must reverse the application fee: pass
  `refund_application_fee: true` on refund creation so Plateful's cut
  comes back to the restaurant too.
- Reject order placement at the controller level if
  `! $restaurant->isStripeReady()` (helper that checks
  `charges_enabled === true`). This shouldn't happen in practice because
  `canGoLive` enforces it, but it's a defense-in-depth check.
- Tests:
  - $25 subtotal + $2.06 tax + $5 tip → `application_fee_amount = 25` (not 32).
  - $50 subtotal → `application_fee_amount = 50`.
  - Restaurant with `application_fee_percent = 1.5` → fee scales correctly
    (covers the case where an enterprise deal has a custom percent later).
  - Refunds reverse the application fee.
  - Order placement throws / 422s when the restaurant isn't Stripe-ready.

## Phase 4 — Payouts view in admin console

Replaces what was the Billing page. Lives in the admin console under
`admin.plateful.test/{subdomain}/payouts`.

- New `Admin\TenantAdmin\PayoutsController`:
  - `index(Restaurant)` — lists recent payouts from `/v1/payouts` on the
    connected account, paginated.
  - Shows YTD Plateful fees paid (sum of `application_fee_amount` on
    completed orders for the current year).
  - A "Update bank info" button → redirects through
    `StripeConnectController@dashboard`.
- Route gated by `admin.restaurant.admin` (admins only — staff shouldn't
  see financials).
- Vue page `Admin/TenantAdmin/Payouts.vue`. Simple list, no editing.
- Add a link to it from the admin nav.

## Phase 5 — Sales copy + cleanup

- Owner landing page (`/for-restaurants`): lead with **"1% per order.
  That's it."** in the hero. Add the comparison table from this document.
- Drop any "free trial" copy. Replace with **"Start free, pay only when
  you make money."**
- Onboarding wizard: drop trial-day countdowns; ensure the Stripe step
  reads as "Connect Stripe — required to take payments."
- Grep the codebase for `trial`, `subscription`, `billing` and remove any
  dead references in views/controllers/copy/tests.
- Add a short pricing section/page (`/for-restaurants#pricing` anchor or
  a dedicated `/for-restaurants/pricing` page — owner's call) showing the
  comparison table and the "1% forever" commitment.

---

## Working conventions for this plan

Same as the rest of the project:

- **Ask clarifying questions before substantial work.** Flag trade-offs.
- **No autonomous commits.** Hand over `git add` + commit message at end
  of each phase; user runs it.
- **Pint after PHP edits:** `vendor/bin/pint --dirty --format agent`.
- **Tests required** for every change. Suite must stay green. Run with
  `php -dmemory_limit=512M vendor/bin/pest --compact` (image tests blow
  past the default 128M limit — bumping Herd's CLI memory_limit is on the
  todo list).
- **Wayfinder:** run `php artisan wayfinder:generate` after adding routes;
  Vue imports from `@/actions/...` / `@/routes/...`.
- **Vite local quirk:** local Node is 18.20.4; Vite needs 20.19+. After
  creating new top-level Vue pages, patch stub entries into
  `public/build/manifest.json` so Inertia render tests don't 500. Stubs
  get overwritten on the next real build. Components imported by parent
  pages (e.g. drawers inside `resources/js/pages/Storefront/components/`)
  don't need manifest stubs.
- **Stripe API keys:** the implementation depends on `STRIPE_KEY`,
  `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` being set in `.env`. Local
  dev should use Stripe test-mode keys.

## Decisions still open

- **Webhook secret rotation strategy.** Phase 2 introduces a custom
  webhook controller (since Cashier is removed). Decide whether
  `STRIPE_WEBHOOK_SECRET` is a single value or a multi-secret rotation
  setup before shipping.
- **What happens when a restaurant's connected account becomes
  `restricted`** (Stripe disables payouts mid-relationship — e.g.
  documentation requested). Probably: surface a banner in the admin
  console + the storefront admin bar; don't auto-suspend the restaurant
  immediately. Decide before Phase 2's webhook listener is final.
- **Refund handling UX.** Where does an admin issue a refund? Currently
  refunds happen as part of order cancellation; the application-fee
  reversal flag is added in Phase 3, but the admin surface for partial
  refunds may not exist yet. Confirm with the existing
  `OrdersController::transition` flow before Phase 3.
