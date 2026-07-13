# Plateful — Software TODO

Product roadmap. Full reasoning: [docs/pos-integration-strategy.md](docs/pos-integration-strategy.md).
Deployment & ops runbook: [DEPLOY.md](DEPLOY.md). Launch blockers: §0 below.

**Strategy in one line:** we're the online-ordering + customer-ownership layer that *integrates*
with a restaurant's existing register (Square/Clover first, not Toast) and dispatches delivery
via flat-fee APIs. Target = independent restaurants dependent on DoorDash/Uber.

The build splits into two independent jobs: **get the order to the kitchen** (POS injection or
cloud printer) and **deliver it** (DoorDash Drive / Uber Direct). Sequenced below by dependency.

---

## 0. Launch blockers — clear before selling to anyone
_These gate real revenue and are independent of everything below. Do first._

- [ ] **Stripe live mode**: swap to live keys, create live webhook + `STRIPE_WEBHOOK_SECRET`,
      have first restaurant (Marcos) complete **live** Connect onboarding, place one real
      end-to-end order and confirm application fee + payout land.
- [ ] **Resend transactional email**: sending subdomain (`send.plateful.fyi`) + SPF/DKIM,
      set `MAIL_*` / `RESEND_API_KEY` in Cloud, confirm a live password-reset delivers.
- [ ] **Sentry error monitoring**: set `SENTRY_LARAVEL_DSN` in Cloud. `cloud-check.php` already
      checks for it; confirm errors report before launch.
- [ ] **S3 restaurant-asset storage**: set `FILESYSTEM_RESTAURANT_ASSETS_DRIVER=s3` + AWS creds/
      bucket in Cloud (menu/logo/hero images). `cloud-check.php` verifies these.
- [ ] Final verification: `php scripts/cloud-check.php` shows Stripe LIVE + mail + Sentry + S3
      configured with no recent errors.

## 1. Pricing model change — LOCKED: 4% flat
_Decision (2026-07-10): platform fee = **4% flat of the food subtotal**, charged as the Stripe
Connect application fee **on top of** the restaurant's own Stripe processing (2.9% + 30¢). Tips
and tax are **excluded** from our fee (already true in code — don't change it). Free setup stays.
This replaces the old **1% placeholder** and the earlier **5% / 4%-floor** proposal. Small,
self-contained; unblocks the savings calculator and the whole fee story. Do early._

**Decisions locked (rationale)**
- **Rate: 4.00% flat.** No tiers, no volume discounts. Simple to quote, honest, and a blowout vs.
  the 15–40% the delivery apps take. A high-volume restaurant that ever balks gets negotiated 1:1
  later — not a system to build now.
- **Base: food subtotal only.** Tips and tax are NOT part of our fee. `OrderPlacement` already
  computes on the food subtotal — keep it. (We deliberately do not take a cut of tips.)
- **Model: application fee _on top of_ Stripe** (current architecture). We are NOT absorbing
  Stripe or computing a single "all-in" fee — the one-clean-number idea is a _pitch/marketing_
  framing, not a code change. The restaurant pays Stripe's 2.9% + 30¢ directly (direct charges,
  merchant of record); our 4% is separate on top. Restaurant's effective all-in ≈ ~7.9% of food.
- **Per-order minimum: deferred / optional.** On-top 4% is always positive, so no floor is needed
  to stay solvent. Revisit only if tiny-order economics prove annoying. (Optional future lever:
  "4%, capped at $X/mo" for whales — back-pocket only, out of scope.)
- **Grandfathering:** `default_application_fee_percent` only governs NEW restaurants at creation
  time; existing rows keep their stored rate. Update the test restaurant (Marco's) to 4%
  explicitly if it should reflect the new rate.

**Implementation — DONE (verified 2026-07-13)**
- [x] `config/platform.php`: `default_application_fee_percent` default is `4.00` (value + the
      `PLATFORM_DEFAULT_APPLICATION_FEE_PERCENT` env fallback). The fee is NOT in
      `StripeConnectService` — it's passed in as `applicationFeeCents`. (config/platform.php:20)
- [x] Computation at `OrderPlacement::prepare()` (line 93, `floor(subtotal × percent / 100)`,
      **food subtotal only**) flows the rate through — tips/tax excluded. (OrderPlacement.php:93)
- [x] `UpdateRestaurantFeeRequest` accepts `4`. (UpdateRestaurantFeeRequest.php:21) **Caveat:** the
      accepted range is `0–100`, not the 0–15 sane range intended — see §8 for the tightening item.
- [x] All restaurants (incl. Marco's) are at 4.00% in the DB — a migration backfilled old 1.00 rows.
- [x] `.env` / `.env.example`: `PLATFORM_DEFAULT_APPLICATION_FEE_PERCENT` is unset, so config
      correctly falls back to `4.00`. Nothing to sync.
- [x] Tests assert 4% and explicitly prove tax + tip are excluded (StripeCheckoutTest.php:55-75).

**Docs to reconcile (repo currently contradicts itself — 1% and 5% both appear)**
- [x] `README.md` "Pricing model" line says **4%** on food subtotal. (README.md:7)
- [x] `docs/pos-integration-strategy.md` §5–§6 say the locked **4%**.
- [ ] (separate `plateful-sales` repo) `PLATEFUL_OVERVIEW.md` + `PROJECT_STATE.md` reference
      1%→5% → update to **4%** so sales and code agree. **(Only remaining §1 item — external repo.)**

---

## 2. POS order-injection — the linchpin (Phase 0 → 1)

### 2a. Foundations (net-new primitives — build before any adapter) — DONE (verified 2026-07-13)
_All the plumbing is built. The `PosDispatcher` is registered with an intentionally empty adapter
map — pushes return `provider_unavailable` until the first adapter (§2c) registers. The one
foundation piece NOT built (the OAuth connect flow) has moved to §2c, where it belongs with Square._
- [x] `PosProvider` contract in `app/Contracts` (`name()`, `supports(Restaurant)`,
      `pushOrder(Order, PosIntegration): PosPushResult`). (PosProvider.php:11)
- [x] `PosDispatcher` (`app/Services/Pos`) resolving a per-tenant adapter from an injected keyed
      map; registered in `AppServiceProvider` (empty map for now). (AppServiceProvider.php:47)
- [x] `PosProviderName` enum (`Square`, `Clover`; `Toast` later). Plus `PosIntegrationStatus` enum.
- [x] **Per-tenant ENCRYPTED credential store** — `pos_integrations` table + `PosIntegration`
      model: `provider`, `external_merchant_id`/`location_id`, `access_token`, `refresh_token`,
      `token_expires_at`, `status`, `scopes`, `last_error`; `encrypted` casts on tokens; unique
      `(restaurant_id, provider)`. Also adds `pos_provider`/`pos_ticket_id`/`pos_pushed_at`/
      `pos_push_failed_at` to `orders`.
- [x] Fire the POS push from the post-commit tail of `OrderPlacement` via
      `queuePostPaymentFulfillment()` → `PushOrderToPos` job, right where confirmation emails queue,
      deduped by the existing idempotency; records `pos_ticket_id`. (OrderPlacement.php:261)
      **This same hook now also fires delivery dispatch — see §3 (no longer "no caller").**
- [x] Failure states: `PushOrderToPos` job has retries/backoff, token-expiry flips integration
      status, and every attempt is logged as an `OrderEvent`. (PushOrderToPos.php)

### 2b. Menu / item mapping (the hidden hard part)
- [ ] Per-tenant reference map (`plateful_menu_item_id → pos_catalog_item_id`) with a guided admin
      matcher (fetch POS catalog, auto-match by name, staff confirms). Text-fallback for unmatched
      items. (Plateful modifiers are shared templates; POS uses per-item modifier lists —
      impedance mismatch; v1 maps references, does not two-way sync.)

### 2c. First adapters
- [ ] **"Connect your POS" OAuth flow** — the foundations are built but this is NOT. Today a
      status-only admin page exists, hard-disabled ("Connect — coming soon"), with `available =>
      false` and no OAuth routes (PosIntegrations.vue:77, PosIntegrationsController). Build the
      connect/callback routes + token handshake writing into the existing `pos_integrations` store.
      Lands with the Square adapter (its OAuth), modeled on Stripe Connect onboarding.
- [ ] `SquarePosProvider` first (`pushOrder`, catalog fetch) + register it in the `PosDispatcher`
      map in `AppServiceProvider` (currently `[]`) — sharpest wedge.
- [ ] `CloverPosProvider` next.
- [ ] Toast later/maybe (gated partner API; we don't save Toast restaurants money — deprioritized).

### 2d. Register-only path
- [ ] Cloud-printer path (Star/Epson CloudPRNT) for restaurants with no smart POS — order just
      prints in the kitchen, no tablet to babysit.

---

## 3. Delivery dispatch (Phase 2)
_Independent of POS; can partly parallelize. `DeliveryDispatcher` + contract + DTOs exist,
`SelfDeliveryProvider` is built and registered, and the dispatcher IS now wired into the order
flow: `OrderPlacement` → `DispatchDeliveryForOrder` job → `DeliveryDispatcher::dispatch()`
(OrderPlacement.php:286, guarded to delivery orders). Enum lists Self/DoorDash/Uber; only `Self`
is registered. Remaining = the two third-party adapters below. (Note: `DeliveryDispatcher::quote()`
has no caller yet — it becomes live once these adapters need real-time quotes; see §8.)_

- [ ] `UberDirectProvider` **first** — Uber Direct is self-serve OAuth via the developer portal.
- [ ] `DoorDashDriveProvider` second — Drive production access is GATED (certification + required
      live demo, no timeline). Start the interest/certification request early, in parallel.

---

## 4. Customer-ownership features (the "own your customers" payoff)
_Head start: a `restaurant_customer` pivot already stores per-restaurant order counters
(`total_orders`, `total_spent_cents`, `first/last_ordered_at`), but it holds no contact info
(email/phone live on `User`) and is surfaced only on the customer's own storefront loyalty view —
never in the tenant admin. So this is a partial data foundation, not a blank slate._
- [ ] Surface a per-restaurant customer contact list (join `restaurant_customer` → `User` for
      email/phone) in the tenant admin; add CSV export. No admin customer page exists today.
- [ ] Fee-free remarketing: email/SMS campaigns — core differentiator vs DoorDash/Toast.
      (Only transactional mail exists today — no campaign/broadcast/newsletter infrastructure.)

## 5. Public savings calculator (prospect-facing; needs pricing locked, §1)
- [ ] Public marketing-site calculator: inputs = monthly delivery volume, current effective
      commission %, Toast add-ons; output = projected monthly/annual savings vs Plateful.
      (Reuse logic in `docs/plateful_fee_comparison.xlsx`.)
- [ ] Lead capture + "book a demo" on the result.

## 6. Onboarding automation (reduces setup friction — enables the "free setup" pitch)
- [x] AI menu import from **photos + PDF** — `MenuImportController` → `ExtractMenuJob` →
      `MenuExtractionService` (Claude `claude-opus-4-8` via `CLAUDE_API_KEY`), with a staff review
      step before commit. Plus universal upload→webp conversion including iPhone HEIC/HEIF
      (`PhotoConversionService`, Imagick). (verified 2026-07-13)
- [ ] **URL / website menu import** — the remaining gap. Import path today is uploaded files only
      (photo + PDF); there is no URL/website-scrape source. Add a fetch-and-extract path that feeds
      the same `MenuExtractionService` review flow.

## 7. Revenue-role split — payout follow-ups
_The split is built (2026-07-13): Founder 10% / Overseer 90% / Recruiter 0% of Plateful's
retained fee, an **attribution ledger** (`fee_distributions`) + a monthly earnings report at
`/super/earnings`. It records who earned what; it does not move money. These are the deferred
pieces._

- [ ] **Refund handling** — the earnings report currently excludes only *fully*-refunded orders
      (`refunded_at` set). Add **partial-refund proration** so a partially-refunded order's
      attributed fee shrinks proportionally (or clawback the ledger rows on refund). Ties into the
      partial-refund UX question in "Open Stripe questions" below.
- [ ] **Direct deposits** — actually paying overseers/recruiters is out-of-band manual today
      (read the monthly report, send a transfer). Decide the payout mechanism (manual bank
      transfer vs. automated Stripe transfers to each payee's own connected account) and, if
      automated, build the transfer + payout-record + reconciliation flow.

---

## 8. Code-hygiene / small guards (surfaced by the 2026-07-13 audit)
_Low-effort correctness & cleanup items found while auditing the roadmap against the code._

- [ ] **Tighten fee validation range.** `UpdateRestaurantFeeRequest` accepts `0–100`
      (UpdateRestaurantFeeRequest.php:21); a fat-fingered 40% fee would pass. Narrow to the
      intended sane range (e.g. 0–15) to prevent an accidental predatory rate.
- [ ] **`refunded_cents` is written but never read.** `OrderTransition` always sets it to the full
      `total_cents` (OrderTransition.php:83) and no code consults it. It's the natural hook for the
      §7 partial-refund proration — wire it there, or drop the column if partials stay out of scope.
- [ ] **`DeliveryDispatcher::quote()` has no caller.** Dead until the Uber/DoorDash adapters (§3)
      need live quotes. Leave a note or fold the quote step into those adapters when built.

---

## Suggested sequence
1. **§0 launch blockers** + **§1 pricing** (parallel; both small, both gate revenue/story).
2. **§2a foundations** (credential store is the keystone — everything POS depends on it).
3. **§2b/2c Square injection + pickup** = first sellable POS milestone.
4. **§3 delivery** and **§2d printer** to widen the addressable set (can overlap §2c).
5. **§4 customer ownership** to make the pitch real; **§5 calculator** once pricing is locked.
6. **§6 onboarding automation** as an ongoing friction-reducer.

---

## Open Stripe questions (carried over from the archived Stripe Connect plan)
_Lifted from `docs/archive/STRIPE_IMPLEMENT_PLAN.md` (migration shipped) so these decisions
aren't lost. Confirm/resolve before the relevant work._

- [ ] **Webhook secret rotation.** Decide whether `STRIPE_WEBHOOK_SECRET` is a single value or a
      multi-secret rotation setup (the custom webhook controller replaced Cashier's).
- [ ] **Restricted connected accounts.** What happens when a restaurant's Stripe account goes
      `restricted` mid-relationship (payouts disabled, docs requested)? Leaning: surface a banner
      in the admin console + storefront admin bar; do NOT auto-suspend the restaurant.
- [ ] **Refund UX.** Where does an admin issue a partial refund? Full refunds already reverse the
      application fee via order cancellation; confirm the surface for partials against the
      `OrdersController::transition` flow.
