# DoorDash Drive — Implementation Plan

**Status:** in progress · **Decision locked 2026-07-18** · Supersedes the Uber-first launch plan.

### Progress (updated 2026-07-17)

| Session | What | State |
|---|---|---|
| **1** | DoorDash adapter core (JWT, client, provider, status map) | ✅ **Done** — sandbox-verified live (quote `HTTP 200`) |
| **4a** | Money model, platform side (cap, accounting columns, revenue-split fix) | ✅ **Done** |
| **4b** | Money model, delivery recovery (customer gross-up + central-billing recovery) | ✅ **Done** — §1 model verified live to the cent |
| **2** | Umbrella provisioning + one-click enable UX | ✅ **Done** (not yet run against live sandbox) |
| **3** | Webhooks + provider-agnostic settlement | ✅ **Done** (signature scheme assumed — confirm at portal setup) |
| **0** | Production access request | ⬜ Part A (interest form) + Part B (demo) — not code |
| **5** | Refunds & cancellation policy | ✅ **Done** — recoverable-refund money model, two independent food-refund toggles (pickup/delivery), Settings UI + dedicated onboarding step; full suite green (867). One go-live item: confirm DoorDash's live cancel-fee response field |
| **6** | Production go-live | ⬜ Not started |

Full suite green at 848 tests. Remaining before real money: Session 5, Session 0/6 (prod access +
Stripe live + a running queue worker). The money model (4a+4b) — the final money gate — is complete.

DoorDash Drive is Plateful's **launch delivery provider**. The complete Uber Direct adapter stays
in the tree **dormant** (behind the `DeliveryProvider` contract; `DeliveryFallbackAction` already
supports `try_next_provider`) — do not delete it. This plan is broken into sessions that can each be
implemented independently. **Execution order:** `0 → 1 → 4a → 4b → 2 → 3 → 5 → 6` — the session
*numbers* are stable labels (Session 2 always means provisioning), but they're **run** in that order.
Rationale: Session 1 (thin adapter) first gives a live sandbox dispatch to observe *and* proves
DoorDash's API behaves as documented; then the money model runs, split into **4a** (platform-side:
cap, accounting separation, revenue-split fix — standalone, fully unit-testable, no DoorDash needed)
and **4b** (delivery-recovery: gross-up + central-billing recovery — observed end-to-end against the
Session 1 adapter). **4b is the final gate to production.** Sessions 2–3 harden provisioning/webhooks,
5–6 finish refunds and go-live.

---

## 0. Why DoorDash, and how it differs from Uber (architecture)

DoorDash Drive is an **umbrella / central-billing** model, which is structurally different from the
Uber adapter and drives most of this plan:

| Dimension | Uber Direct (existing, dormant) | DoorDash Drive (this plan) |
|---|---|---|
| Credentials | **Per-restaurant** (`client_id`/`client_secret`/`customer_id`), pasted by owner | **Platform-level**, one set in `.env` (`DOORDASH_DEVELOPER_ID`/`KEY_ID`/`SIGNING_SECRET`). Restaurants paste nothing. |
| Auth | OAuth `client_credentials` → cached bearer token | **DD-JWT-V1** (HS256) minted per-request from the signing secret |
| Per-restaurant identity | The Uber account itself | A **Business + Store** (`external_business_id` / `external_store_id`) Plateful mints via API |
| Who pays the courier | The **restaurant** (Uber bills them directly) | **Plateful** (central billing) → must recover via Stripe application fee |
| Webhook signature | Per-restaurant HMAC, resolve integration by `customer_id` | Platform-level secret, resolve assignment by `external_delivery_id` |
| Onboarding friction | Owner signs up at direct.uber.com, pastes 3 secrets | **One click** — Plateful provisions the Business/Store behind the scenes |

**Verified live (2026-07-17, sandbox):** JWT auth works first try; `POST /drive/v2/quotes` for a SLC
address returned a fee; `POST /drive/v2/quotes/{id}/accept` locks the fee and flips status
`quote → created`. Quote responses carry **no expiry field** (docs say accept within ~5 min).

**Production access is gated** ("currently restricted, no certification timeline"). Sandbox is
self-serve. **File the production-access request on day one** (see Session 0) — it is the long pole.

---

## 1. The money model (the conceptual core)

All amounts in integer cents. Let:
- `F` = food subtotal, `R` = restaurant's own optional delivery charge (they keep it, untaxed),
  `X` = tax, `T` = customer tip (driver tip, routes to Dasher via DoorDash),
  `D` = DoorDash's courier quote (what Plateful pays DoorDash), `rate` = Stripe variable rate
  (config, ~0.029; the fixed 30¢ is excluded — it's the restaurant's normal card cost).

**Customer-facing delivery fee** (shown in the cart, included in the charge total):
```
Dc = round( D × 1.04 / (1 − rate) )        // ≈ 1.071 × D  (4% margin + ~3% Stripe recovery, grossed up)
```

**Plateful commission** (the 4%, capped monthly — see §1.3):
```
commission = min( floor(F × application_fee_percent/100), monthly_cap_remaining )
```

**Stripe `application_fee_amount`** (what Stripe pulls from the restaurant's charge to Plateful):
```
application_fee = commission + round(D × 1.04) + T
```
Note this pulls `1.04×D` (not `Dc`); the difference `Dc − 1.04D` stays in the restaurant's account
and exactly offsets Stripe's fee on the delivery line, so the restaurant bears **no** Stripe fee on
delivery. `T` is pulled so Plateful can forward it to the Dasher.

**Settlement per delivery order:**
- Plateful receives `application_fee`, pays DoorDash `D + T`, **nets `commission + 0.04D`**.
- Restaurant nets `F + R + X − commission`, then bears Stripe on its own money + the tip (~2.9% of
  `T`, accepted — grossing up the tip after the customer picks it reads as bait-and-switch).
- Above the monthly cap, `commission = 0`; Plateful still nets the `0.04D` margin and never absorbs
  a cost, so a whale is never a loss.

### 1.1 Worked example
$30 food, restaurant's own $2 delivery charge, $9 DoorDash quote, $5 driver tip, $2.50 tax, `rate`=0.029, under cap:
- `Dc = round(9 × 1.04 / 0.971)` = **$9.64** → customer pays `30 + 2 + 9.64 + 5 + 2.50` = **$49.14**
- `commission` = `floor(3000 × 4/100)` = **$1.20**
- `application_fee` = `1.20 + round(9 × 1.04) + 5` = `1.20 + 9.36 + 5` = **$15.56**
- Plateful pays DoorDash `9 + 5` = $14.00 → **nets $1.56** (`1.20` commission + `0.36` margin)
- Restaurant nets `49.14 − 15.56` = $33.58 gross; Stripe (`2.9% × 49.14 + 0.30` ≈ $1.73) leaves ≈
  **$31.85** — identical to bearing Stripe only on its own money + tip. ✓

### 1.2 The accounting separation (critical — do not skip)
Today `orders.application_fee_cents` means "Plateful's 4% cut" and `RevenueSplitResolver::record()`
splits **that** column among role-holders (`RevenueSplitResolver.php:71-107`). Under DoorDash the
Stripe application fee becomes a **gross** number that includes DoorDash's `1.04D + T` passthrough.
If the revenue split reads the gross, it would split DoorDash's money among founder/operator/overseer.

**Therefore split Plateful's *true revenue*, not the Stripe gross.** Introduce distinct columns
(Session 4): `platform_commission_cents` (= `commission`), `delivery_margin_cents` (= `0.04D`),
`courier_fee_cents` (= `D`, owed to DoorDash). Repurpose/rename the Stripe number to
`stripe_application_fee_cents` (= the gross actually charged). `RevenueSplitResolver` then splits
`platform_commission_cents + delivery_margin_cents`. The monthly cap and the earnings page read
`platform_commission_cents`.

### 1.3 Monthly earnings cap — $249/restaurant/month
- Config: `platform.commission_monthly_cap_cents` (default `24900`), grandfathered per-restaurant via
  a nullable `restaurants.commission_monthly_cap_cents` column (mirror how `application_fee_percent`
  is snapshotted in `Restaurant::booted()` `Restaurant.php:85-86`).
- At order time, `monthly_cap_remaining = max(0, cap − MTD_commission(restaurant))` where
  `MTD_commission` = `SUM(platform_commission_cents)` for the restaurant in the current calendar
  month **excluding refunded orders** (`whereNull('refunded_at')`, same filter `EarningsController`
  uses). Then clamp `commission` as in §1. Breakeven ≈ $6,225/mo food.
- Best-effort under concurrency (two simultaneous orders could each read the same MTD and slip a few
  cents past the cap). Acceptable for launch — note it; revisit with a lock only if it matters.

---

## 2. Data-model changes (summary) — ✅ all shipped except `refunds_enabled`

- ✅ **`delivery_integrations`** (nullable, **not** encrypted — ids, not secrets):
  `external_business_id`, `external_store_id`. DoorDash rows store these; `client_id`/`client_secret`/
  `customer_id`/`access_token` stay null for DoorDash. Landed in **Session 1** (the adapter reads
  `external_store_id`), not Session 2 as originally sequenced. (Platform JWT creds live in `.env`.)
- **`restaurants`**: ✅ `commission_monthly_cap_cents` (nullable int; default from config on create,
  grandfathered) added in 4a. ⬜ `refunds_enabled` (bool, default OFF) — Session 5.
- ✅ **`orders`** (4a): added `platform_commission_cents`, `delivery_margin_cents`, `courier_fee_cents`
  (with a backfill of `platform_commission_cents` from `application_fee_cents`). **Decided:** kept
  `application_fee_cents` as the Stripe gross (its existing meaning) rather than adding
  `stripe_application_fee_cents` — see Decision 5.
- ✅ **config/platform.php** (4a/1): `commission_monthly_cap_cents`, `stripe_variable_rate`, and a
  `delivery.doordash` block (`base_url` — single host for sandbox+prod, like Uber; quote accept-window
  minutes).
- ✅ **config/services.php**: `doordash.developer_id/key_id/signing_secret/webhook_secret` from `.env`
  (no `environment` key — DoorDash serves sandbox and prod from the same host).

---

## Session 0 — Production access (two parts: interest now, demo at the end)

Not code. Production access is gated ("currently restricted… no timeline… approval not guaranteed
despite checklist completion"), so getting on DoorDash's radar early matters — but the formal
approval hinges on a **live Zoom demo of a complete end-to-end delivery**, which needs the
integration mostly built. So Session 0 splits:

**Part A — now (zero cost):** submit the "record your interest" form
(`https://docs.google.com/forms/d/e/1FAIpQLSfggU_NjGWCdi9vyWUicrnzJmtu9vC4zgbfSC3ROwSvW4eV2g/viewform`)
to start the relationship while sandbox development proceeds.

**Part B — when demo-ready (after Sessions 1–5):** in the Developer Portal, click **Request
Production Access** → confirm business details → **set up the payment method for deliveries** (the
card/ACH DoorDash centrally bills — this is the umbrella billing account) → accept the Drive terms.
Then a 30–60 min Zoom demo where you screenshare an end-to-end test delivery.

**The demo reviews (maps to our sessions):**
- **API logs / required fields** — `external_delivery_id`, and (since we're multi-location)
  `pickup_external_business_id` + `pickup_external_store_id` **required**, plus dropoff contact/phone/
  address components (Sessions 1, 2, 4b).
- **Customer + merchant UI** — must display delivery/support IDs, pickup times, tracking to both the
  customer (storefront) and the "merchant" (our admin) (Sessions 2–3, may need small UI additions to
  surface `support_reference`/tracking).
- **Error handling** — user-friendly messages when DoorDash rejects a quote/request (checkout).
- **Cancellation workflow** — clear merchant/customer cancellation path (Session 5).
- **Restricted-item safeguards** — must block tobacco/cannabis/weapons/etc.; alcohol needs a signed
  addendum + licensing (we're food-only, so mainly assert we don't ship restricted items).
- **Launch strategy** — they ask for the go-to-market plan/timeline (the Utah pilot).

Everything up to the demo is sandbox-testable via the Delivery Simulator; only go-live depends on
approval. Because the demo spans Sessions 1–5, schedule Part B once those are done, not before.

---

## Session 1 — DoorDash adapter core (sandbox, no money changes) — ✅ DONE (2026-07-17)

> **Shipped.** `app/Services/Delivery/DoorDash/` (`DoorDashJwtService`, `DoorDashClient`,
> `DoorDashProvider`, `DoorDashStatusMap`, `DoorDashAddress`), registered in `AppServiceProvider`,
> default provider chain now `['doordash']`. The `delivery_integrations` id migration was pulled
> forward from Session 2 (the adapter can't quote without `external_store_id`). Verified live against
> the DoorDash sandbox: DD-JWT-V1 auth + `POST /drive/v2/quotes` returned `HTTP 200` with a real fee.
> Tests: `DoorDashProviderTest`, `DoorDashJwtServiceTest`. Note: DoorDash `fee` **excludes** the tip
> (unlike Uber), so no tip-stripping. `create()` re-quotes on accept failure (R1).

**Goal:** a registered `DoorDashProvider` that quotes, dispatches, polls, and cancels a delivery in
sandbox for a restaurant whose Business/Store is configured manually. Delivery works end-to-end
reusing the *existing* fee logic (money model comes in Session 4).

**Build** `app/Services/Delivery/DoorDash/` (mirror `app/Services/Delivery/UberDirect/`):
- `DoorDashClient.php` — HTTP surface; base URL from config (sandbox/prod); `authed()` attaches a
  freshly-minted JWT. Mirror `UberDirectClient` shape.
- `DoorDashJwt.php` (+ `DoorDashJwtService`) — mint DD-JWT-V1 HS256 from platform
  `developer_id`/`key_id`/`signing_secret` (config, **not** per-restaurant). Reference implementation
  already proven in `scratchpad/doordash_quote_smoke.php`. No stored/cached token needed (JWTs are
  cheap and short-lived), so this is simpler than `UberDirectTokenService`.
- `DoorDashProvider.php` implements `app/Contracts/DeliveryProvider.php`:
  - `name()` → `DeliveryProviderName::DoorDash`
  - `supports(Restaurant)` → integration row exists with `external_store_id` set + status `Connected`
  - `quote(DeliveryQuoteRequest)` → `POST /drive/v2/quotes` with a generated `external_delivery_id`,
    `pickup_external_business_id/store_id` from the restaurant's integration, pickup from restaurant
    address columns, dropoff from the request. Map response `fee`→`feeCents`, ETAs. **Set a synthetic
    `expiresAt = now + accept-window (config ~5 min)`** since DoorDash returns none — this lets
    `DeliveryDispatcher::quoteForDispatch()` re-quote proactively. Store the `external_delivery_id`
    as `externalQuoteId`.
  - `create(Order, DeliveryQuote)` → `POST /drive/v2/quotes/{external_delivery_id}/accept` with the
    driver `tip`. This is DoorDash's "commit" (vs Uber's separate create). On accept failure
    (expired/consumed quote), re-quote fresh and accept — see Risk R1. Persist a `DeliveryAssignment`
    (reuse the model as-is; it's provider-agnostic). Store `actual_fee_cents = D` (courier fee).
  - `status(DeliveryAssignment)` → `GET /drive/v2/deliveries/{external_delivery_id}`; forceFill+save.
  - `cancel(DeliveryAssignment)` → `PUT /drive/v2/deliveries/{external_delivery_id}/cancel`.
- `DoorDashStatusMap.php` — map DoorDash status vocab (`created`, `confirmed`, `enroute_to_pickup`,
  `picked_up`, `enroute_to_dropoff`, `delivered`, `cancelled`, …) → the shared `DeliveryStatus` enum.

**Wire:**
- Register in `AppServiceProvider.php:48-51`: `DeliveryProviderName::DoorDash->value => $app->make(DoorDashProvider::class)`.
- Add `'doordash'` to the default chain in `DeliveryDispatcher::providerChainFor()` (`~L42-43`,
  where the "add when §9 lands" comment is). Only now, since the adapter exists.
- Config block for creds + sandbox base URL.

**Tests** (Pest, `tests/Feature/Delivery/DoorDashProviderTest.php`, mirror `UberDirectProviderTest`):
`Http::fake()` the DoorDash endpoints; assert quote maps fee/eta, create hits `/accept` and persists
an assignment, status/cancel work, accept-failure triggers a re-quote. Add a JWT unit test asserting
the signed token verifies against the secret.

**Done when:** with a hand-seeded DoorDash `DeliveryIntegration` row, a delivery order dispatches
through the existing `DispatchDeliveryForOrder` job to the DoorDash sandbox and the webhook-less
`ExpireAuthorizedDelivery` poll path sees status. (Money still computed the old way.)

---

## Session 2 — Umbrella provisioning + one-click enable UX — ✅ DONE (2026-07-17)

> **Shipped.** `DoorDashProvisioningService::provisionStoreFor()` mints deterministic external ids
> (`pf-biz-{id}` / `pf-store-{id}`), POSTs `/developer/v1/businesses` then `.../stores`, persists ids
> + status Connected; **409 is treated as success** (idempotent re-enable). `DoorDashClient` gained
> `developerPath()`. Controller: DoorDash added to `$connectable` (now **leads** the card list);
> `enableDoorDash` (one-click, parks Error + reason on failure) + `disconnectDoorDash`. Routes named
> `delivery.doordash.save`/`.disconnect` to match the card's saveUrl convention. Vue renders a single
> **"Enable delivery"** button (`oneClick` flag), shows Store ID, hides the per-restaurant webhook-key
> warning. Tests in `DeliveryIntegrationsTest`. The `external_business_id`/`external_store_id`
> migration already landed in Session 1. **Not yet run against the live sandbox** (creates named
> Business/Store records — deferred to a supervised check).

**Goal:** a restaurant enables DoorDash delivery with **one click**; Plateful provisions its
Business + Store via API and stores the ids. No secrets pasted.

**Build:**
- Migration: add `external_business_id`, `external_store_id` to `delivery_integrations`.
- In `DoorDashProvider` (or a `DoorDashProvisioningService`, mirroring
  `StripeConnectService::createExpressAccount` `StripeConnectService.php:~155`):
  `provisionStoreFor(Restaurant)` → `POST /developer/v1/businesses` then
  `POST /developer/v1/businesses/{id}/stores` using the restaurant's address columns
  (`street/city/state/postal_code/phone`; **no lat/lng exists** — DoorDash geocodes the address like
  Uber does). Persist ids onto the `DeliveryIntegration` row via `updateOrCreate` +
  `withoutTenantScope()`; set status `Connected`. Keep ids out of `$fillable` on any restaurant-side
  write, matching the Stripe id convention (`Restaurant.php:35-72` excludes stripe ids).
- Enablement UX (mirror the Uber card, but no credential form):
  - `DeliveryIntegrationsController`: add `DeliveryProviderName::DoorDash` to `$connectable`
    (`~L37/L413`). Add `enableDoorDash(Restaurant)` (calls provisioning) and
    `disconnectDoorDash(Restaurant)` (nulls ids, status `Disconnected`). No `...CredentialsRequest`
    needed — there's nothing to validate from the owner.
  - Routes in `routes/super-admin.php` (~L84-87) named `delivery.doordash.enable` /
    `delivery.doordash.disconnect` (the Vue reads `saveUrl`/`disconnectUrl` by convention).
  - `resources/js/pages/Admin/TenantAdmin/DeliveryIntegrations.vue`: render the DoorDash card with a
    single **"Enable delivery"** button instead of the `provider==='uber'` credential form.

**Tests:** `Http::fake` the businesses/stores endpoints; assert enabling creates the integration with
ids and status `Connected`; assert the card renders as available and the button posts to the enable
route; assert disconnect clears ids.

**Done when:** a restaurant admin clicks Enable and gets a working DoorDash integration with zero
credential entry, in sandbox.

**Note:** requires production access before this does anything real; until then it provisions sandbox
businesses/stores. Gate the button copy accordingly if needed.

---

## Session 3 — Webhooks + provider-agnostic settlement — ✅ DONE (2026-07-17)

> **Shipped.** `DoorDashWebhookController` on `POST /webhooks/doordash` (CSRF-exempt): verifies a
> **platform-level** HMAC-SHA256 signature (`services.doordash.webhook_secret`, header
> `x-doordash-signature`, **fail-closed** with no secret), resolves the assignment directly by
> `external_delivery_id`, maps status, drops stale events, and drives the same `DeliverySettlement`
> path. `hasCourier()` **generalized onto the `DeliveryStatus` enum**; static copies removed from both
> status maps; the deadline job + both webhooks now call `$status->hasCourier()`. Tests:
> `DoorDashWebhookTest`. **⚠ One unverified item:** the exact signature scheme (header + base64-vs-hex)
> is assumed — the controller accepts both encodings and it's centralized in `signatureIsValid()`, but
> confirm against the DoorDash portal at webhook setup (Session 6).

**Goal:** DoorDash courier status updates drive auth/capture the way Uber's do, and remove the
Uber-coupling in the shared settlement path.

**Build:**
- `app/Http/Controllers/DoorDashWebhookController.php` (mirror `UberDirectWebhookController`):
  - **Verify DoorDash's signature scheme** — likely HMAC-SHA256 of the raw body against a
    platform-level webhook secret (config), compared with `hash_equals`. Confirm exact header/scheme
    against DoorDash docs during this session.
  - Resolve the `DeliveryAssignment` directly by `external_delivery_id` (= our `external_id`), not by
    a per-restaurant id (DoorDash creds are platform-level). Reuse the `[provider, external_id]` index.
  - Map status via `DoorDashStatusMap`, drop stale events via `last_event_at`, write `OrderEvent`,
    then call the same `settlePayment()` path (`DeliverySettlement::onCourierConfirmed` /
    `onCourierUnavailable`).
  - Route: `routes/super-admin.php` alongside `webhooks.uber` (~L20), name `webhooks.doordash`,
    CSRF-exempt.
- **Generalize `hasCourier`:** `ExpireAuthorizedDelivery.php:74` calls `UberDirectStatusMap::hasCourier`
  directly. Move `hasCourier()` onto the shared `DeliveryStatus` enum (a status is "has courier" when
  `DriverAssigned` or later) and have both status maps + the job + both webhooks call the enum method.
  This is a small refactor that keeps DoorDash and Uber settlement identical.
- Register the DoorDash webhook secret in config; surface the webhook URL in the admin page like
  Uber's (`webhookUrl` prop) if DoorDash needs it registered in their portal.

**Tests:** signed-webhook POST tests (valid/invalid signature → 200/400), status transitions drive
`onCourierConfirmed`/`onCourierUnavailable`, stale-event drop, and the generalized `hasCourier`.

**Done when:** a simulated DoorDash webhook captures/voids a held payment correctly, and
`ExpireAuthorizedDelivery` works for both providers with no Uber-specific code.

---

## Session 4a — Money model, platform side (standalone; no DoorDash needed) — ✅ DONE (2026-07-17)

> **Shipped.** New `orders` columns `platform_commission_cents` / `delivery_margin_cents` /
> `courier_fee_cents` (+ backfill copying `application_fee_cents` → commission for existing rows) and
> nullable `restaurants.commission_monthly_cap_cents` (grandfathered in `Restaurant::booted()`).
> Config `platform.commission_monthly_cap_cents` (24900) + `platform.stripe_variable_rate` (0.029).
> `MonthlyCommissionCap` caps commission at $249/mo in the **restaurant's local** calendar month,
> excluding refunded orders. `RevenueSplitResolver` + PayoutsController YTD read
> `platform_commission_cents`; EarningsController reads the ledger, so it followed automatically.
> **Decisions taken** (see Open Decisions 1 & 5 below): (1) `application_fee_cents` **kept as the
> Stripe gross** (its continuous meaning) with `platform_commission_cents` added as the true-revenue
> column — reversing Open Decision 5's "add stripe_application_fee_cents" recommendation, for less
> churn; (2) the delivery margin gets its **own `RevenueRole::DeliveryMargin`** (100% founder) because
> the `(order,user,role)` unique key forbids a second founder row. Tests: `MonthlyCapTest`,
> `RevenueSplitResolverTest`, `StripeCheckoutTest`.

**Goal:** the cap, the accounting-column separation, and the revenue-split fix — the parts of §1 that
depend on nothing about DoorDash and are fully unit-testable today against the existing checkout
suite. Runs right after Session 1 so the hardest core work is de-risked early.

**Build:**
- **Migrations:** add the new `orders` columns (`platform_commission_cents`, `delivery_margin_cents`,
  `courier_fee_cents`, and `stripe_application_fee_cents` — see Open Decision 5) and
  `restaurants.commission_monthly_cap_cents` (nullable; default from config on create, mirroring
  `application_fee_percent` in `Restaurant::booted()` `Restaurant.php:85-86`).
- **Config:** `platform.commission_monthly_cap_cents` (default `24900`) and `platform.stripe_variable_rate`.
- **Cap + commission:** at `OrderPlacement.php:131`, replace `application_fee_cents = floor(F×pct/100)`
  with `commission = min(floor(F × pct/100), monthly_cap_remaining)` where `monthly_cap_remaining =
  max(0, cap − MTD_commission(restaurant))`, MTD summing `platform_commission_cents` for the current
  calendar month excluding refunded orders (§1.3). Persist `platform_commission_cents = commission`.
  For pickup / self-delivery, `stripe_application_fee_cents = commission` (behavior unchanged except
  the cap now applies). `delivery_margin_cents`/`courier_fee_cents` stay `0` until 4b populates them.
- **Revenue split:** change `RevenueSplitResolver::record()` (`:71-107`) so it splits
  `platform_commission_cents` by the existing role shares, and attributes `delivery_margin_cents`
  **100% to the founder** as a separate `FeeDistribution` row (Open Decision 1 — configurable later,
  e.g. via a `platform.delivery_margin_shares` config; margin is `0` until 4b so this is dormant now).
  `EarningsController` MTD/earnings reads `platform_commission_cents`.
- **Cap read:** a small `MonthlyCommissionCap` query/helper shared by the resolver and `OrderPlacement`.

**Tests:** `MonthlyCapTest` (clamps at $249, resets per month, refunded orders excluded from MTD, the
concurrency note in §1.3). `RevenueSplitTest` asserting the split reads true commission, not the gross.
Extend `StripeCheckoutTest` for pickup/self-delivery: fee unchanged except the cap, columns populated.

**Done when:** the cap clamps at $249 and the revenue split reads Plateful's true commission — all
pinned by tests, with no DoorDash involved.

---

## Session 4b — Money model, delivery recovery (final gate to production) — ✅ DONE (2026-07-17)

> **Shipped.** New `app/Services/Delivery/DeliveryMarkup.php`:
> `customerFeeCents(D, pct) = round(D × (1+pct/100) / (1−rate))`, `marginCents(D, pct) = round(D×pct/100)`.
> **Gated on `DeliveryProviderName::isCentrallyBilled()` (DoorDash only)** — Uber bills the restaurant
> directly, so it stays pass-through and its tests are untouched. `OrderPlacement` sets
> `courier_fee_cents = D`, `delivery_margin_cents`, and `application_fee_cents (Stripe gross) =
> commission + D + margin + T` for a centrally-billed delivery; `DeliveryQuoteController` +
> `customerDeliveryFeeCents` gross up so quoted and charged prices can't drift. **Generalized the
> plan's literal 4% to the restaurant's `application_fee_percent`** (matches the worked example at 4%).
> **Verified live:** a real sandbox quote `D=975` → `Dc=1044`, app fee `1634`, Plateful nets `159`,
> restaurant nets ≈$29.91 bearing zero Stripe on the delivery line — §1.1 to the cent. Tests:
> `DoorDashDeliveryMoneyTest`, `DeliveryMarkupTest`. Full Stripe-settlement end-to-end (real test
> charge + running queue worker) remains a manual pre-go-live check.

**Goal:** the DoorDash-specific half of §1 — the customer gross-up and central-billing recovery —
observed **end-to-end** against the Session 1 sandbox adapter. **No production DoorDash orders until
this lands.**

**Build:**
- **Delivery pricing gross-up:** the customer-facing third-party delivery fee becomes
  `Dc = round(D × 1.04 / (1 − rate))`. Implement in the third-party branch of the fee strategy
  (`DeliveryFeeStrategy::customerFeeCents`, `app/Enums/DeliveryFeeStrategy.php`) /
  `OrderPlacement::customerDeliveryFeeCents` (`OrderPlacement.php:245-250`). Third-party delivery is
  **always pass-through-with-markup** (customer bears); `Absorb` remains meaningful only for
  self-delivery (Open Decision 3).
- **Application fee for delivery orders:** extend the 4a math so a third-party delivery order sets
  `delivery_margin_cents = round(0.04 × D)`, `courier_fee_cents = D`, and
  `stripe_application_fee_cents = commission + round(1.04 × D) + T`. Pass the **Stripe gross** to
  `StripeConnectService::createCheckoutSession` as `application_fee_cents` (`CheckoutController.php:104`,
  `StripeConnectService.php:49`). The `delivery_margin_cents` now flows into the revenue split from 4a.
- Wire it so a sandbox DoorDash delivery order (dispatched via the Session 1 adapter) settles with the
  exact money in §1's worked example — and verify it end-to-end, not just via unit tests.

**Tests:** extend `StripeCheckoutTest` (which "pins every exclusion") for third-party delivery: exact
`Dc`, `stripe_application_fee_cents`, restaurant net, `delivery_margin_cents`, `courier_fee_cents`,
across under-cap / over-cap. Assert the margin reaches the revenue split.

**Done when:** a sandbox DoorDash delivery order matches §1's worked example end-to-end and the
customer/restaurant/Plateful/DoorDash splits are correct to the cent.

---

## Session 5 — Refunds & cancellation policy — ✅ DONE (2026-07-17)

> **Shipped.** Two **independent** food-refund toggles (Decision 4 refined): `pickup_refunds_enabled`
> / `delivery_refunds_enabled` (+ `refund_policy_reviewed_at`), all default OFF, surfaced on the
> Settings page **and** a dedicated optional onboarding step (`StepRefunds.vue`, `onboarding.refundPolicy`,
> stamped reviewed so a deliberate "no refunds" still completes). New `DeliveryProvider::cancel()`
> returns a `DeliveryCancellation` (courier-fee charged), parsed from DoorDash's cancel response;
> `OrderTransition::refundOnCancel` cancels the courier first, then refunds only the **recoverable**
> amount via `RefundCalculator` → `RefundPlan`: food per policy, delivery only when the courier fee
> came back. Partial refunds use `StripeConnectService::refundOrderPartial` (explicit
> Application-Fee-Refund amount, not the boolean); `RevenueSplitResolver::reverse` deletes the reversed
> ledger slices and the reversed order columns are zeroed, so MTD/earnings stay honest.
> `refunded_at` is set only on a **100%** refund, so a partial delivery/food refund still counts its
> retained revenue. **Conservative default:** when the cancel response is silent the courier fee is
> assumed kept (never refund on a guess → Plateful stays whole). Tests: `DeliveryRefundTest`,
> `RefundCalculatorTest`, `DoorDashProviderTest` (cancel-parse), settings + onboarding tests.
> **⚠ One go-live item:** DoorDash's exact cancel-response fee field is unverified — centralized in
> `DoorDashProvider::parseCancellation()`; confirm at portal setup (Session 6).

**Goal:** refunds mirror DoorDash (timing) + the restaurant's food-refund choice; Plateful never
eats a refund; the 4% reverses proportionally.

**Build:**
- `restaurants.refunds_enabled` flag, **default OFF** for new restaurants (Open Decision 4) — but
  **surface the choice in the onboarding setup flow** (add a small step/toggle to the wizard in
  `OnboardingController::steps()` `OnboardingController.php:215-258`, not just buried in settings) so
  every owner makes a deliberate call at setup. Also a toggle on the delivery/settings admin page.
- Cancellation flow: when a delivery order cancels, call DoorDash cancel; branch on whether DoorDash
  refunds the courier fee (pre-pickup) or not (post-pickup — DoorDash keeps `D`). Refund the customer
  **only** what DoorDash refunds (delivery) plus what the restaurant's policy allows (food). Never
  refund from Plateful's pocket.
- Use `StripeConnectService::refundOrder` (`:143-151`, already sets `refund_application_fee => true`)
  for full refunds; for **partial** refunds (food-only, or delivery-only) compute the refund amount
  and the matching application-fee reversal so `platform_commission_cents` for that order drops to $0
  (or proportionally) and the order is excluded from MTD/earnings via `refunded_at`.
- Verify DoorDash's exact cancellation-fee schedule from their pricing doc during this session (left
  unverified in research); the code reacts to whatever the cancel response says rather than assuming.

**Tests:** pre-pickup cancel (delivery refunded), post-pickup cancel (delivery retained, food per
policy), refunds-disabled restaurant (no food refund), commission reversal + MTD exclusion.

**Done when:** each cancellation scenario refunds exactly the recoverable amount and Plateful is
never out of pocket.

---

## Session 6 — Production go-live

- Swap DoorDash config from sandbox to production credentials/base URL (once access is granted).
- Restrict/secure the production keys; confirm the webhook secret is set in the DoorDash portal.
- Regression pass: Uber adapter still present and dormant (a test asserting it's registered but not
  in the default chain).
- Update `todo.md` and the project memory; retire the Uber-first framing in
  `docs/uber-direct-implementation-plan.md` (add a pointer to this doc).

---

## Decisions (resolved 2026-07-18)

1. **Delivery margin in the revenue split** — DECIDED: **100% to the founder for now**, configurable
   later. **Implemented (4a)** as its own `RevenueRole::DeliveryMargin` ledger row rather than a
   second founder row — the `(order, user, role)` unique key forbids two founder rows per order, so a
   dedicated role is the faithful realization and stays splittable later (e.g.
   `platform.delivery_margin_shares`). Commission still splits by the existing role shares.
4. **`refunds_enabled` default** — DECIDED: **default OFF**, surfaced in the onboarding flow so owners
   decide deliberately. ✅ **Implemented (Session 5), refined to TWO independent toggles**
   (`pickup_refunds_enabled` / `delivery_refunds_enabled`) so a restaurant can allow refunds on one
   channel but not the other; both default OFF, surfaced on Settings + a dedicated onboarding step.

### Resolved during implementation
2. **Cap month boundary / timezone.** ✅ **Implemented (4a): restaurant's local calendar month**
   (`MonthlyCommissionCap` computes the local start-of-month, compares against stored UTC `placed_at`).
3. **`Absorb` fee strategy for third-party delivery.** ✅ **Implemented (4b): a centrally-billed
   provider (DoorDash) is always pass-through-with-markup**, keyed on
   `DeliveryProviderName::isCentrallyBilled()`; the strategy is ignored for it. A pass-through provider
   (Uber) keeps `Absorb`, and self-delivery keeps its flat fee. Scoped to central billing so dormant
   Uber behaviour and its tests are unchanged.
5. **`application_fee_cents` column** — ✅ **Implemented (4a), reversing this recommendation:** kept
   `application_fee_cents` as the Stripe gross (its existing, continuous meaning — it has always been
   "the Stripe application fee amount") and **added `platform_commission_cents`** as the new
   true-revenue column. Less churn than adding `stripe_application_fee_cents` (CheckoutController
   unchanged), and no silent meaning change since the gross only diverges from commission on the new
   delivery case. Also added `delivery_margin_cents` / `courier_fee_cents`.

### Still open
- **DoorDash webhook signature scheme** (Session 3) — header/encoding assumed; confirm at portal setup.
- **DoorDash cancel-response fee field** (Session 5) — the exact field for a cancellation charge is
  assumed (conservative "kept" default); confirm at portal setup, alongside the webhook scheme.

## Risks

- **R1 — Quote accept window vs Stripe's 30-min session.** DoorDash quotes accept within ~5 min;
  a customer lingering on the Stripe Checkout page can blow past it before dispatch. Mitigation:
  dispatch fires seconds after payment auth, and `create()` re-quotes+re-accepts on accept failure.
  Residual: a re-quote at dispatch can differ from the customer's locked checkout price → Plateful
  eats rare drift (the auth amount is fixed). Small and infrequent; monitor. Consider a tiny exposure
  buffer only if it shows up.
- **R2 — Production access gating.** No committed timeline; Session 0 mitigates by filing early.
  Everything up to Session 4 is sandbox-testable regardless.
- **R3 — Cap race under concurrency.** Best-effort MTD read; a few cents of overage possible. Accept
  for launch; add a per-restaurant lock only if needed.
- **R4 — Passthrough Stripe fee on the tip.** ~2.9% of `T` is borne by the restaurant (accepted, §1).
  Not a bug — a deliberate, documented choice.

## Testing conventions (reuse)
`Http::fake()` for all DoorDash HTTP (template: `tests/Feature/Admin/DeliveryIntegrationsTest.php`,
`tests/Feature/Delivery/UberDirectProviderTest.php`). Stripe via partial mock / `constructFrom`
(template: `tests/Feature/StripeConnectTest.php`). Money assertions extend
`tests/Feature/Storefront/StripeCheckoutTest.php`. Run targeted: `php artisan test --compact --filter=DoorDash`.

## Sequencing
**Execution order: `0 → 1 → 4a → 4b → 2 → 3 → 5 → 6`** (session numbers are stable labels, not run
order). Session 0 (file prod request) runs immediately and in parallel. Session 1 (thin adapter)
gives a live sandbox dispatch and proves DoorDash's API. **4a** (cap + accounting + revenue-split fix)
is standalone and de-risks the hard core early; **4b** (delivery gross-up + central-billing recovery)
is observed end-to-end against the Session 1 adapter and is **the final gate to production**. Sessions
2–3 harden one-click provisioning and webhooks; 5–6 finish refunds and go-live. Uber stays dormant
throughout.
