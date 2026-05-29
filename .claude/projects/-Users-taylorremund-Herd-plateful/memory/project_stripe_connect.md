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

**Phase 3 is the hard one** and not yet started: there is NO customer payment flow today — `OrderPlacement` writes orders to the DB with `application_fee_cents => 0` and `CheckoutController::store` makes zero Stripe calls. Phase 3 = build card collection + PaymentIntent-on-connected-account + refund money paths from scratch, not "add a fee param."

Conventions for this work: [[feedback_clarify_before_code]], [[feedback_no_auto_commits]]. NOTE: `composer require/remove` triggers `boost:update`, which strips the custom "Account Model" section out of `CLAUDE.md` — re-add it after any composer dependency change.
