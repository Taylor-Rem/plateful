# Plateful — Software TODO

Product roadmap. Full reasoning: [docs/pos-integration-strategy.md](docs/pos-integration-strategy.md).
Deployment & ops runbook: [DEPLOY.md](DEPLOY.md). Launch blockers: §0 below.

**Strategy in one line:** we're the online-ordering + customer-ownership layer that *integrates*
with a restaurant's existing register (Square/Clover first, not Toast) and dispatches delivery
via flat-fee APIs. Target = independent restaurants dependent on DoorDash/Uber.

The build splits into two independent jobs: **get the order to the kitchen** (POS injection or
cloud printer) and **deliver it** (DoorDash Drive / Uber Direct). Sequenced below by dependency.

**Position (2026-07-15):** both jobs now have a shipped path — Square + Clover injection, and Uber
Direct delivery end-to-end. The remaining work is mostly *launch*, not build: §0 is still open and
Stripe is still in test mode.

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
- [ ] **S3 restaurant-asset storage**: set `FILESYSTEM_DISK=s3` and leave `MEDIA_DISK` unset, + AWS
      creds/bucket in Cloud (menu/logo/hero images). `cloud-check.php` reports the **effective**
      media disk, not just the raw vars. (Corrected 2026-07-15: this item used to say
      `FILESYSTEM_RESTAURANT_ASSETS_DRIVER=s3` — that var is read by nothing, and DEPLOY.md paired it
      with `FILESYSTEM_DISK=local`, so following the runbook would have parked every upload on the
      container's ephemeral disk while the check printed green. The real knob is `config/media.php`:
      `MEDIA_DISK` if set, else `FILESYSTEM_DISK`.)
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
- [x] Computation at `OrderPlacement::prepare()` (`floor(subtotal × percent / 100)`,
      **food subtotal only**) flows the rate through — tips/tax excluded.
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
_All the plumbing is built. The `PosDispatcher` map now registers Square and Clover; a restaurant
whose connected provider has no adapter still degrades safely (`provider_unavailable`). The one
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
      deduped by the existing idempotency; records `pos_ticket_id`.
      (`OrderPlacement::queuePostPaymentFulfillment()`)
      **This same hook now also fires delivery dispatch — see §3 (no longer "no caller").**
- [x] Failure states: `PushOrderToPos` job has retries/backoff, token-expiry flips integration
      status, and every attempt is logged as an `OrderEvent`. (PushOrderToPos.php)

### 2b. Menu / item mapping (the hidden hard part)
_Text-fallback shipped in §2c (each order line pushes as an ad-hoc Square line item with selected
options folded into the line `note`). The guided catalog matcher below is the remaining, harder half._
- [x] **Text-fallback for unmatched items** — `SquarePosProvider` pushes ad-hoc line items
      (name, qty, `base_price_money` = `unit_price_cents`) + option names in the line `note`. Never
      drops a line. Plateful stays the pricing authority. (SquarePosProvider.php)
- [ ] Per-tenant reference map (`plateful_menu_item_id → pos_catalog_item_id`) with a guided admin
      matcher (fetch POS catalog via `ITEMS_READ`, auto-match by name, staff confirms), so tickets
      reference real Square catalog objects instead of text. (Plateful modifiers are shared
      templates; POS uses per-item modifier lists — impedance mismatch; v1 maps references, does not
      two-way sync.)

### 2c. First adapters — Square + Clover code-complete (2026-07-13)
_"Verified" here means built and covered by `Http::fake` tests — **neither has pushed to a real
register.** Both live-sandbox tests skip for want of credentials; see the two items below._
- [x] **"Connect your POS" OAuth flow** — `SquareOAuthService` + `SquareConnectController`
      (connect/callback/disconnect) + routes, writing into `pos_integrations`. Redirect URI lives on
      the **admin host** (`admin.plateful.test/pos/square/callback`) because `SESSION_DOMAIN=null`
      scopes the session there; the restaurant travels in a single-use, 15-min `state` (session-
      stashed), not the URL. Admin page now shows live Connect/Reconnect/Disconnect for Square
      (`available => true`); Clover still "coming soon". `SQUARE_*` creds + config in `.env` /
      `config/services.php`. Tests: SquareOAuthServiceTest, SquareConnectTest, PosIntegrationsPageTest.
- [x] `SquarePosProvider::pushOrder` against the Square Orders API (text-fallback lines, §2b),
      registered in the `PosDispatcher` map in `AppServiceProvider`. **Token refresh (was step 4)
      folded in**: `freshAccessToken()` refreshes proactively when the token is expired/<5 min out
      and persists the rotated token; a 401 throws `PosTokenExpiredException`. `SquareClient` owns the
      host + pinned API version. Tests: SquarePushOrderTest (full-pipeline via `Http::fake`),
      SquareLiveSandboxTest (opt-in real-sandbox push+read-back, skipped without creds). **Catalog
      fetch/matching deferred to §2b.**
- [x] **Clover adapter — DONE (verified 2026-07-13, 19 Clover tests).** `CloverClient` (split
      authorize vs. API hosts), `CloverOAuthService` (v2/OAuth expiring tokens; single-use rotating
      refresh via `/oauth/v2/refresh`, no scope param — permissions set on the Clover app),
      `CloverTokens`, `CloverPosProvider` (atomic-order push, quantity expanded into repeated lines,
      option text-fallback note), `CloverConnectController` (connect/callback/disconnect; `merchant_id`
      read from the callback query — no location lookup). Registered in the `PosDispatcher` map and
      added to the admin `$connectable` list (`available => true`). `CLOVER_*` env + `config/services.php`.
      Tests: CloverOAuthServiceTest, CloverPushOrderTest, CloverConnectTest, CloverLiveSandboxTest
      (opt-in real-sandbox push+read-back, skipped without creds — mirrors Square's).
- [ ] **Square live verification — same gap as Clover's, and it was never tracked.** Corrected
      2026-07-15: `SQUARE_SANDBOX_ACCESS_TOKEN` / `SQUARE_SANDBOX_LOCATION_ID` are not set, so
      `SquareLiveSandboxTest` **skips** — it has never run. Neither adapter has pushed to a real
      register; only Uber Direct is genuinely sandbox-verified (its 5 live tests do run and pass
      locally, because the `UBER_DIRECT_SANDBOX_*` creds are in `.env`). §2c reads as though Square
      is proven and only Clover is pending; they are in identical shape. Note the two skips are the
      *only* skips in the suite, so a green run says nothing about either POS adapter.
- [ ] **Clover live verification.** `CLOVER_*` app creds are entered in
      `.env` and verified resolving (authorize URL builds against the sandbox host). Still to do: (a)
      run the OAuth connect flow once against a sandbox test merchant to prove the handshake (redirect
      match + Orders R/W permission), and (b) run `CloverLiveSandboxTest` with `CLOVER_SANDBOX_ACCESS_TOKEN`
      + `CLOVER_SANDBOX_MERCHANT_ID` to prove a real order push. Needs a Clover sandbox test merchant.
- [ ] Toast later/maybe (gated partner API; we don't save Toast restaurants money — deprioritized).

**Operational note (to run a live integration):** the push is a queued job and `QUEUE_CONNECTION=
database`, so a queue worker must be running (`composer run dev` includes `queue:listen`, or run
`php artisan queue:work`) or connected orders never actually push. To verify against real POS
sandboxes: Square — set `SQUARE_SANDBOX_ACCESS_TOKEN` + `SQUARE_SANDBOX_LOCATION_ID` and run
SquareLiveSandboxTest; Clover — set `CLOVER_SANDBOX_ACCESS_TOKEN` + `CLOVER_SANDBOX_MERCHANT_ID`
and run CloverLiveSandboxTest.

### 2d. Register-only path
- [ ] Cloud-printer path (Star/Epson CloudPRNT) for restaurants with no smart POS — order just
      prints in the kitchen, no tablet to babysit.

---

## 3. Delivery dispatch (Phase 2) — **Uber Direct COMPLETE (2026-07-15)**
_Built end-to-end and verified against the real Uber sandbox: per-restaurant credentials, the
adapter, status webhooks, Places address capture, quote-before-payment, and auth/capture. The
customer now gets a committed fee and ETA before paying, and is only ever charged once a courier
actually exists. `DeliveryFeeStrategy` is wired and `DeliveryDispatcher::quote()` has a caller.
DoorDash (§9 of the plan) is all that remains, and is deliberately later._

**Full plan: [docs/uber-direct-implementation-plan.md](docs/uber-direct-implementation-plan.md)**
— kept current as it was built; carries the decisions, the corrections the live API forced, and the
"Before production" checklist. **Read it before touching any of this.**

**Done 2026-07-14**
- [x] Live bug: every storefront offered delivery regardless of `delivery_enabled`, charged the fee,
      and the dispatch job returned silently. Gated in `OrderPlacement::prepare()`.
- [x] `UberDirectProvider` (quote/create/status/cancel) + per-restaurant `delivery_integrations` +
      `UberDirectTokenService`. Verified live: real token, real priced quote.
- [x] Status webhooks. The signing key is **per-restaurant** — each restaurant creates its own
      webhook in its own Uber dashboard, so there is no platform secret.
- [x] Quote before payment. A failed quote = delivery not offered, which is the out-of-range check
      for free.
- [x] Dropped `customer_delivery_fee_cents_max` and `DeliveryFeeStrategy::Split`; wired
      `PassThrough` + `Absorb`. `prep_time_minutes` added (default 5).
- [x] Delivery settings page — seven flags that had no UI at all. Delivery can no longer be enabled
      without choosing a mode, which silently meant third-party.
- [x] Fixed `DeliveryDispatcher` defaulting the provider chain to `['doordash','uber']`.

**Done 2026-07-15 — §8 auth/capture**
- [x] Courier-network deliveries **hold** the money and capture only once Uber confirms a driver;
      no driver → the hold is released and the customer sees it drop off, never a charge+refund.
      Pickup and self-delivery still capture at checkout — holding funds to wait for nothing is
      pure downside.
- [x] `PaymentState` (`captured`/`authorized`/`voided`) is its own column, NOT an `OrderStatus`
      case: `OrderStatus` is the kitchen lifecycle and "authorized" is not something a cook acts on.
- [x] The POS push is held until the courier is confirmed, off the same signal as the capture.
- [x] The deadline job polls Uber before giving up, so a missing webhook costs latency rather than
      correctness. Without it, a restaurant that skipped webhook setup would have had **every**
      delivery silently cancel with a courier en route.
- [x] **Fixed shipped code:** `OrderTransition::refundOnCancel()` refunded the PaymentIntent, which
      Stripe rejects when uncaptured — it failed silently (best-effort) and stranded the hold on the
      customer's card. Voided orders were being refunded for a charge that never existed too.

**Next**
- [ ] `DoorDashDriveProvider` — same contract, one line in `AppServiceProvider`. Drive production
      access is GATED (certification + required live demo, no timeline). Start the
      interest/certification request early, in parallel. Then add `'doordash'` to the
      `DeliveryDispatcher` chain default and the admin `$connectable` list.

**Before this can take real money** (full list in the plan)
- [ ] **A queue worker must be running.** Delivery dispatch and the auth/capture deadline are queued
      jobs on `QUEUE_CONNECTION=database`. Without a worker, authorized orders never dispatch AND
      never expire — holds sit on customer cards with nothing scheduled to release them. This is the
      one operational dependency with no in-code backstop. Worth a Sentry alert on
      `payment_state = 'authorized'` older than an hour.
- [ ] Provision Plateful's **production** Uber account — it can currently mint only
      `direct.organizations`, not `eats.deliveries`. Sandbox working says nothing about it.
- [ ] Rotate the production Uber Client Secret (exposed in a session transcript 2026-07-14).
- [ ] Restrict the Google Maps key to Places API (New) + the production server's IP.
- [ ] Each restaurant creates its own Uber webhook and pastes the signing key. Optional but wanted:
      without it every delivery waits out the courier deadline before capturing.

---

## 4. Customer-ownership features (the "own your customers" payoff)
_Head start: a `restaurant_customer` pivot already stores per-restaurant order counters
(`total_orders`, `total_spent_cents`, `first/last_ordered_at`), but it holds no contact info
(email/phone live on `User`) and is surfaced only on the customer's own storefront loyalty view —
never in the tenant admin. So this is a partial data foundation, not a blank slate._
- [ ] **Loyalty redemption — points can be earned but never spent, and we advertise otherwise.**
      (Surfaced 2026-07-15. The only gap found that was both user-visible and untracked.)
      Built today: `LoyaltyService::awardForOrder()` grants `floor(subtotal_cents / 100) ×
      platform.loyalty.points_per_dollar` (currently 1) when an order goes Completed
      (`OrderTransition:51`), idempotent via `orders.awarded_loyalty_points`; `LoyaltyController`
      shows the balance and recent earning orders. **`awardForOrder()` is the service's only
      method** — there is no debit, redeem, or spend path anywhere in `app/`, and no reversal or
      clawback on cancel/refund. Points accrue forever as an unbounded liability.
      The problem isn't just the missing feature — we already promise it:
      - `Welcome.vue:323` — "Redeem at the place you earned it" on the public landing page.
      - `Legal/Terms.vue:107` — "Points are earned and redeemed with the specific Restaurant."
        (Hedged: "Restaurants **may** offer… terms, value, and availability are set by each
        Restaurant" — so this is softer than the landing-page claim, but still points at a
        redemption flow that doesn't exist.)
      Decide before building — these are product calls, not code ones:
      - **What do points buy?** ($ off subtotal, a free item, tiers?) And who sets the rate —
        platform-wide, or per-restaurant? Terms currently says the restaurant sets it, and
        `points_per_dollar` is platform-wide config, so those already disagree.
      - **Fee interaction.** Our 4% is on the food subtotal. If points discount the subtotal, our
        fee shrinks with it and the restaurant eats the discount — confirm that's intended, and
        that a fully-points-paid order can't produce a $0/negative charge.
      - **Schema.** `loyalty_points` is a single mutable balance row per `(user_id, restaurant_id)`
        — a bare integer, no ledger. Spending against it leaves no audit trail and no way to answer
        "where did my points go", and it races under concurrent redemption
        (`awardForOrder` already reaches for `lockForUpdate`). A redemption path probably wants an
        events table, with the balance derived or reconciled — same shape as `fee_distributions`
        in §7.
      - **Reversal.** A refunded or cancelled order keeps its points today. Ties into §7's
        partial-refund proration and §8's `refunded_cents` question — same underlying gap.
      Interim option if this stays deferred: soften `Welcome.vue:323` so we stop promising a flow
      that isn't built. Cheap, and it's the only part with a live customer-facing claim.
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
- [ ] **`refunded_cents` is written but never read.** `OrderTransition` sets it to the full
      `total_cents` on a refunded cancel (`OrderTransition::refundOnCancel()`) and no code consults it. It's the
      natural hook for the §7 partial-refund proration — wire it there, or drop the column if
      partials stay out of scope. (Since §8, an order cancelled while only *authorized* is voided
      instead and correctly leaves this at 0 — nothing was charged, so nothing was refunded.)
- [x] ~~**`DeliveryDispatcher::quote()` has no caller.**~~ Closed 2026-07-14: the quote now gates
      checkout (§3), so it is called on every third-party delivery address.
- [x] **Six pre-existing TypeScript errors.** Fixed 2026-07-15. `npm run types:check` is green.
      Two were real bugs, not type noise:
      - `Unavailable.vue` used `defineOptions({ layout: null })` to opt out of the storefront
        chrome, but Inertia v3 resolves `page.layout ?? defaultLayout(...)` — **null falls through**,
        so the page rendered wrapped in `StorefrontLayout` on a response that has no restaurant.
        Only the `app.ts` resolver can opt a page out; it now returns `null` for that page.
      - `Orders/Index.vue` passed `preserveScroll`/`preserveState` to `usePoll`; `reload()` spreads
        both as `true` *after* caller options, so they were no-ops (hence `ReloadOptions` omits them).
      - `AppHeader.vue`'s missing `dashboard` import died with the dead subtree (see below).
      - `Checkout.vue` / `Menu.vue` read server-only form-level error keys — now via the
        `Record<string, string>` cast already used in `StepReview.vue`.
- [x] **CI never gated anything** (root cause, fixed 2026-07-15). `lint.yml` ran the *fixing*
      variants (`composer lint` = `pint` write-mode, `npm run format` = `prettier --write`,
      `npm run lint` = `eslint --fix`) with the auto-commit step commented out, so it mutated the
      runner and exited clean regardless. `types:check` was in no workflow at all. Both workflows
      also triggered on `develop`/`master`/`workos` — none of which exist — and **not** on `dev`,
      the working branch. That is how ~430 violations accumulated under a green CI. Now: `lint.yml`
      runs the `:check` variants, `tests.yml` runs `types:check` after the build (vue-tsc needs the
      gitignored Wayfinder output), and both trigger on `main` + `dev`. `composer ci:check` covers
      the same ground locally — but note it runs `php artisan test`, which needs
      `memory_limit` > 128M (run pest directly with `-d memory_limit=2G`).
- [x] **Dead starter-kit frontend removed** (2026-07-15). `AppHeaderLayout.vue` had zero importers
      and was the only importer of `AppHeader.vue`; Vite never bundled the subtree, so only `vue-tsc`
      ever saw its broken `dashboard` import. Deleted with `PlaceholderPattern.vue`,
      `AuthCardLayout.vue`, `AuthSplitLayout.vue`, the orphaned `ui/{badge,collapsible,select,
      navigation-menu}`, and `tests/Unit/ExampleTest.php`. (`ui/tooltip` and `ui/skeleton` look
      orphaned but are used by the live sidebar — leave them.)
- [ ] **More verified dead code, not yet removed.** All zero-reference, confirmed against the route
      table: `routes/settings.php` (never loaded by `bootstrap/app.php`; its six routes are
      duplicated at `routes/storefront.php:96-131`, which is where the live ones come from);
      `RequirePlatformHost` + its `'platform'` alias (zero routes); `MenuItemReorderRequest` (no
      route or controller); `orders.stripe_transfer_id` (zero references anywhere); write-only
      columns `voided_at` / `captured_at` / `authorized_at` / `restaurants.suspension_reason`;
      `restaurants.delivery_provider_priority` (read by `DeliveryDispatcher`, written only by tests,
      so always NULL in production); ~10 superseded migrations incl. a 7-migration Cashier chain for
      a package never in `composer.json`; `config('platform.admin_notification_email')` and
      `services.stripe.key`, neither ever read.

## 9. Menu availability & order pausing (surfaced 2026-07-14 while scoping §3 delivery)
_Availability itself is built and enforced: `menu_items.is_available` + `item_template_options.is_available`,
with `OrderPlacement::validateCartLines()` blocking unavailable items AND options at checkout
(`OrderPlacement::validateCartLines()`), and `isOpenAt()` rejecting closed-restaurant orders
(`OrderPlacement::prepare()`).
These are the gaps around it — and they got sharper now that §3 shipped: a delivery order quotes an
arrival time and sends a courier, so an 86'd item or an unnoticed rush is no longer just a bad
pickup experience._

- [ ] **Time-boxed 86 / snooze.** `is_available` is a permanent boolean an owner must remember to
      flip back — no "sold out until 4pm", no auto-expiry. Add `unavailable_until` (nullable) so an
      item self-heals, and a one-click 86 toggle: today the only way to mark an item out is to open
      the full `MenuItemEditDrawer` and save the whole record.
- [ ] **No ingredient model.** "We're out of avocado → 86 everything with avocado" is not
      expressible; availability is per-item and per-option only. Real feature, only worth it if
      owners actually ask.
- [ ] **Pause-orders kill switch.** `restaurant_hours` is the ONLY way to stop the flow — an owner
      slammed at lunch has to edit their hours to stop taking orders. Add a manual
      `orders_paused_until` / `is_accepting_orders` guard checked in `OrderPlacement::prepare()`
      alongside `isOpenAt()`.
- [ ] **`isOpenAt()` returns `true` when a restaurant has no hours rows** (Restaurant.php:404) —
      deliberate "always open" back-compat, but it means deleting your hours silently accepts orders
      24/7. Now that hours gate delivery dispatch, decide: keep the back-compat or fail closed.
- [ ] **`CartManager::addItem` never checks `is_available`** (CartManager.php:110) — an unavailable
      item can be added via a direct POST (route-model-bound `MenuItem`, no filter). Caught at
      checkout by `validateCartLines`, so it's a late failure rather than an ordering hole; the cart
      drawer surfaces it client-side via `CartItemData::isAvailable`. Move the check earlier.

## 10. Loyalty — points are earned but can never be spent (surfaced 2026-07-15 by codebase audit)
_The earn path is shipped and tested: `LoyaltyService::awardForOrder()` fires on the transition to
`Completed` (`OrderTransition.php:51`), credits `floor(subtotal_cents / 100) × points_per_dollar`,
is idempotent via `orders.awarded_loyalty_points`, skips guest orders, and is surfaced read-only at
`/account/loyalty` (`LoyaltyController`). **`awardForOrder()` is the only method on the service.**
There is no debit, redeem, or spend path anywhere in `app/` — grepping "redeem" repo-wide returns
two hits, both marketing copy. Points accrue forever against a balance nobody can draw down._

**Why this is not just a missing feature**
- **The landing page already promises it.** `Welcome.vue:323` — "Redeem at the place you earned it."
  A prospect reading the marketing site is told redemption exists today. It does not.
- **Growing liability.** Every completed order adds points. The longer the gap runs, the larger the
  balance you eventually have to honor — or explain away.
- **`Terms.vue:104-112` contradicts the code.** It says "The terms, value, and availability of
  rewards are set by each Restaurant." `points_per_dollar` is a **platform-wide constant**
  (`config/platform.php:108`, value `1`) with no per-restaurant column or admin UI. No restaurant
  sets anything. The Terms are hedged enough ("Restaurants *may* offer") to be defensible, but they
  describe a system that isn't built.

**Decisions to make before building any of it**
- [ ] **Redemption mechanism.** Dollar-off at checkout, a free item at a threshold, or tiers? This
      is the product decision everything else hangs off.
- [ ] **Does redemption cut our fee? (ties to §1 — pricing.)** Our 4% is charged on the **food
      subtotal**. If points redeem as dollars off that subtotal, then every redemption shrinks our
      application fee *and* the restaurant absorbs the discount. If it applies after the fee is
      computed, our fee holds and the restaurant still eats the discount. Neither is obviously
      right, but it is a **pricing decision, not an implementation detail** — decide it explicitly
      rather than discovering it in `OrderPlacement::prepare()`.
- [ ] **Who sets the rate.** Either add per-restaurant loyalty config (matching what the Terms
      already claim) or correct the Terms to say Plateful sets it. Today neither is true.
- [ ] **Add a ledger.** `loyalty_points` is a single `points` integer per `(user_id, restaurant_id)`
      (`2026_05_18_170004_create_orders_tables.php:45-52`) — a balance with **no transaction
      history**. Earning-only survives that; spending does not. Without rows you cannot answer
      "where did my points go", reverse a mistake, or reconcile a dispute.
- [ ] **Clawback on refund.** Points are awarded at `Completed` and **never reversed** — nothing in
      `OrderTransition` or `DeliverySettlement` touches them on cancel or refund, so a
      completed-then-refunded order keeps its points. Free points for an order that generated no
      revenue. Same family as §7's partial-refund proration and §8's `refunded_cents`.
- [ ] **Expiry / breakage.** The Terms mention forfeiture on account termination; nothing else
      expires. Decide whether points age out, and note that `unavailable_until`-style self-healing
      (§9) is the same shape of problem.

**Cheapest way to close the exposure today (no feature work):** soften `Welcome.vue:323` so the
public site stops advertising redemption until it exists. Worth doing regardless of when §10 lands.

---

## Suggested sequence
1. **§0 launch blockers** + **§1 pricing** (parallel; both small, both gate revenue/story).
2. ~~**§2a foundations**~~ — done.
3. ~~**§2b/2c Square injection + pickup**~~ — Square + Clover adapters done; catalog matcher open.
4. ~~**§3 delivery**~~ — Uber Direct complete 2026-07-15. Both halves of the strategy ("get the
   order to the kitchen" and "deliver it") now have a shipped path.
5. **Next: §0 launch blockers.** The build has outrun the launch prep — delivery is done and Stripe
   is still in test mode. §0 + §3's "Before this can take real money" list are what stand between
   this and a paying restaurant.
6. **§4 customer ownership** to make the pitch real; **§5 calculator** once pricing is locked.
   **§2d printer** widens the addressable set; **§6 onboarding automation** is an ongoing
   friction-reducer.
7. **§10 loyalty** is the one gap the marketing site already sells as shipped, so it sets its own
   deadline. Its fee-base question belongs with **§1**, not after it — decide that while pricing is
   still fresh. The one-line `Welcome.vue` copy fix closes the exposure until the feature lands.

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
