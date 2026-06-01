---
name: project_stripe_connect
description: Stripe Connect migration progress, plan location, and key IA decisions
metadata:
  type: project
---

Plateful is migrating from Cashier subscription billing to a **Stripe Connect per-order 1% application fee** model. Authoritative plan: `STRIPE_IMPLEMENT_PLAN.md` (repo root, 5 phases).

Progress (as of 2026-05-29):
- **Phase 1 done** — removed Cashier, `Billable`, billing routes/controller/Vue, the trial-suspension command, `platform.billing.*` config, `trial_ends_at`; migration dropped `subscriptions`/`subscription_items` tables + Cashier columns. Kept `stripe_account_id`, `stripe_account_status`, `application_fee_percent`.
- **Phase 2 done** — `composer require stripe/stripe-php`; `StripeConnectService` (in `app/Services/Stripe/`), `StripeConnectController`, `StripeWebhookController` (account.updated), routes in `routes/super-admin.php`, CSRF-exempt webhook at `admin.plateful.test/stripe/webhook`. Status vocabulary on `stripe_account_status`: `pending`/`enabled`/`restricted` (see `Restaurant::isStripeReady()`). Stripe is now a **required** onboarding step (`canGoLive` gates on `isStripeReady`).

Key decision: **Connect onboarding lives in the ADMIN CONSOLE** (grafted onto the existing `OnboardingController`/`Onboarding.vue`), NOT the storefront — this overrides the plan's "lives on the storefront" IA section, chosen for pragmatism since the onboarding wizard + `canGoLive` already live in the console. See [[project_admin_routing]].

**Phase 3 done** (the big one — built the whole payment flow from scratch). Locked decisions: **direct charges**, **Stripe-hosted Checkout**, **pay-first** (order materializes only after payment), **full-refund-on-cancel** default. Flow: `CheckoutController::store` validates → snapshots to a new `pending_checkouts` row → creates a Checkout Session on the connected account (`application_fee_amount` = 1% of food subtotal only) → `Inertia::location()` to Stripe. Order materializes idempotently (unique `orders.stripe_checkout_session_id`) via BOTH the `checkout.session.completed` webhook AND the `paymentReturn` success handler, through `OrderPlacement::materialize()`/`completeCheckout()`. `place()` kept as the synchronous path. Refund-on-cancel wired into `OrderTransition` (`refund_application_fee: true`). Per-restaurant refund policy deferred (did NOT overload `auto_cancel_refund_mode`).

Test note: storefront checkout tests use `tests/Feature/Storefront/CheckoutTestHelpers.php` (`fakeCheckoutSession()` + `payLatestCheckout()`); `cartFixture()` is now Stripe-ready. KNOWN PRE-EXISTING FAILURE (not Phase 3): `MenuPageTest` "nav Menu link" asserts raw built markup and needs the Vite dev server / hot mode running (`npm run dev`); proven via git-stash. Phase 3 is verified by tests only — no real Stripe round-trip yet (no keys in .env).

**Phase 4 done** — `PayoutsController` + `Admin/TenantAdmin/Payouts.vue` at `admin.plateful.test/{subdomain}/payouts` (admin-only via `admin.restaurant.admin`). Lists connected-account payouts (`StripeConnectService::listPayouts`) + YTD Plateful fees (sum of `application_fee_cents` for this year's non-refunded orders). "Update bank info" → Express dashboard. Nav link added to `TenantAdminLayout.vue` (admin-only).

**Phase 5 done** — `ForRestaurants/Landing.vue` leads with "1% per order. That's it.", adds a `#pricing` comparison table + "1% forever" copy, drops the "free trial" line. Tidied stale "billing" comments in `RestaurantStatus`/`ResolveTenant`.

ALL 5 PHASES COMPLETE. Suite 466/466 green. Still no real Stripe round-trip (mocked; needs test keys + `stripe listen`). New top-level Vue pages need a `public/build/manifest.json` stub (gitignored) for headless render tests since the local Vite build can't run (Node 18).

Conventions for this work: [[feedback_clarify_before_code]], [[feedback_no_auto_commits]]. NOTE: `composer require/remove` triggers `boost:update`, which strips the custom "Account Model" section out of `CLAUDE.md` — re-add it after any composer dependency change.
