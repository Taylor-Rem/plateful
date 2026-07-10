# Plateful — Software TODO

Product roadmap. Full reasoning: [docs/pos-integration-strategy.md](docs/pos-integration-strategy.md).
Launch/ops checklist: [LAUNCH_PLAN.md](LAUNCH_PLAN.md).

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
- [ ] Final verification: `php scripts/cloud-check.php` shows Stripe LIVE + mail configured.

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

**Implementation (for Claude Code)**
- [ ] `config/platform.php`: change `default_application_fee_percent` default `1.00` → `4.00`
      (and the `PLATFORM_DEFAULT_APPLICATION_FEE_PERCENT` env fallback). The fee is NOT in
      `StripeConnectService` — it's passed in as `applicationFeeCents`.
- [ ] Confirm the computation at `OrderPlacement::prepare()` (~line 85,
      `floor(subtotal × percent / 100)`, **food subtotal only**) flows the new rate through with
      no logic change — tips/tax stay excluded.
- [ ] `UpdateRestaurantFeeRequest`: ensure validation accepts `4` and a sane range (e.g. 0–15) so
      super admins can still set/override a per-restaurant rate in the console.
- [ ] Update the test restaurant (Marco's) stored rate to 4% (seeder or manual) if desired.
- [ ] Sync `.env` / `.env.example` if `PLATFORM_DEFAULT_APPLICATION_FEE_PERCENT` is set there.
- [ ] Tests: update any assertion of the 1% fee; add a case proving a 4% fee on a known food
      subtotal **excludes tax and tip**. Run `php artisan test --compact`.

**Docs to reconcile (repo currently contradicts itself — 1% and 5% both appear)**
- [ ] `README.md` "Pricing model" line says **1%** → change to **4%** (+ note it's on food subtotal).
- [ ] `docs/pos-integration-strategy.md` §5–§6 say **5% (4% floor)** → update to the locked **4%**.
- [ ] `LAUNCH_PLAN.md` Stripe blocker references the **1%** application fee → change to **4%**.
- [ ] (separate `plateful-sales` repo) `PLATEFUL_OVERVIEW.md` + `PROJECT_STATE.md` reference
      1%→5% → update to **4%** so sales and code agree.

---

## 2. POS order-injection — the linchpin (Phase 0 → 1)

### 2a. Foundations (net-new primitives — build before any adapter)
- [ ] Define a `PosProvider` contract in `app/Contracts` mirroring `DeliveryProvider`
      (`name()`, `supports(Restaurant)`, `pushOrder(Order): PosPushResult`, `syncMenu()` later).
- [ ] `PosDispatcher` (`app/Services/Pos`) resolving a per-tenant adapter from an injected keyed
      map; register in `AppServiceProvider` exactly like `DeliveryDispatcher`.
- [ ] `PosProviderName` enum (`Square`, `Clover`, `Toast` later).
- [ ] **Per-tenant ENCRYPTED credential store** — NET NEW, none exists today. New
      `pos_integrations` table (tenant-scoped via `BelongsToTenant`): `provider`,
      `external_merchant_id`/`location_id`, `access_token`, `refresh_token`, `token_expires_at`,
      `status`, `scopes`; `encrypted` casts on tokens; token-refresh flow. Delivery creds can
      share this store.
- [ ] Tenant-facing "Connect your POS" OAuth UI in admin, modeled on Stripe Connect onboarding.
- [ ] Fire the POS push from the post-commit tail of `OrderPlacement::materialize()` (async/queued)
      after payment confirms — right where the confirmation emails queue. Covers both the checkout
      return path and the Stripe webhook in one spot, deduped by the existing
      `stripe_checkout_session_id` idempotency. Record POS ticket id. **NOTE:** this same call site
      is where delivery dispatch (§3) must be wired — `DeliveryDispatcher` exists but has no caller
      yet. Build the post-commit hook once; both features hang off it.
- [ ] Failure states (never silently drop a paid order): POS down → retry + alert; token expired
      → refresh/flag; item-mapping mismatch → push as plain line item + text note. Log every
      attempt as an `OrderEvent`.

### 2b. Menu / item mapping (the hidden hard part)
- [ ] Per-tenant reference map (`plateful_menu_item_id → pos_catalog_item_id`) with a guided admin
      matcher (fetch POS catalog, auto-match by name, staff confirms). Text-fallback for unmatched
      items. (Plateful modifiers are shared templates; POS uses per-item modifier lists —
      impedance mismatch; v1 maps references, does not two-way sync.)

### 2c. First adapters
- [ ] `SquarePosProvider` first (OAuth connect, `pushOrder`, catalog fetch) — sharpest wedge.
- [ ] `CloverPosProvider` next.
- [ ] Toast later/maybe (gated partner API; we don't save Toast restaurants money — deprioritized).

### 2d. Register-only path
- [ ] Cloud-printer path (Star/Epson CloudPRNT) for restaurants with no smart POS — order just
      prints in the kitchen, no tablet to babysit.

---

## 3. Delivery dispatch (Phase 2)
_Independent of POS; can partly parallelize. `DeliveryDispatcher` + contract + DTOs exist and
`SelfDeliveryProvider` is built, BUT the dispatcher has no caller yet — wiring it into
`OrderPlacement` is net-new and shared with the POS push (§2a). Enum lists DoorDash/Uber._

- [ ] `UberDirectProvider` **first** — Uber Direct is self-serve OAuth via the developer portal.
- [ ] `DoorDashDriveProvider` second — Drive production access is GATED (certification + required
      live demo, no timeline). Start the interest/certification request early, in parallel.

---

## 4. Customer-ownership features (the "own your customers" payoff)
- [ ] Capture + surface per-restaurant customer contact list (email/phone); export.
- [ ] Fee-free remarketing: email/SMS campaigns — core differentiator vs DoorDash/Toast.

## 5. Public savings calculator (prospect-facing; needs pricing locked, §1)
- [ ] Public marketing-site calculator: inputs = monthly delivery volume, current effective
      commission %, Toast add-ons; output = projected monthly/annual savings vs Plateful.
      (Reuse logic in `docs/plateful_fee_comparison.xlsx`.)
- [ ] Lead capture + "book a demo" on the result.

## 6. Onboarding automation (reduces setup friction — enables the "free setup" pitch)
- [ ] Tools to auto-build a restaurant's menu/storefront quickly (import from menu/URL/photo).

---

## Suggested sequence
1. **§0 launch blockers** + **§1 pricing** (parallel; both small, both gate revenue/story).
2. **§2a foundations** (credential store is the keystone — everything POS depends on it).
3. **§2b/2c Square injection + pickup** = first sellable POS milestone.
4. **§3 delivery** and **§2d printer** to widen the addressable set (can overlap §2c).
5. **§4 customer ownership** to make the pitch real; **§5 calculator** once pricing is locked.
6. **§6 onboarding automation** as an ongoing friction-reducer.
