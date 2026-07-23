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

- [ ] **Credential rotation (audit 2026-07-16).** A repo/machine-wide scan found **no secret in git
      history and none in any tracked file** — `.env` has never been committed. But secret values
      had accumulated in **7 local Claude session transcripts** (`~/.claude/projects/`), including
      the production Uber Client Secret. Those transcripts are now **redacted in place**
      (values replaced with `[REDACTED-<KEY>]`; sessions still intact) — but **redaction is not
      rotation**: every value below is still live until rotated at its source. Priority order:
      - [ ] `LARAVEL_CLOUD_TOKEN` — highest value: it can read the production env, which will soon
            hold **live** Stripe keys. Rotate in the Laravel Cloud dashboard.
      - [ ] **Production Uber Client Secret** — rotate when provisioning the prod account (§3).
      - [ ] `CLAUDE_API_KEY` — live and billed; one click in the Anthropic console.
      - [ ] Low value / rotate at leisure: `SQUARE_APPLICATION_SECRET`, `CLOVER_APP_SECRET`,
            `GOOGLE_CLIENT_SECRET`, `GOOGLE_MAPS_API_KEY` (the IP restriction below matters more),
            the Uber **sandbox** secret, and `APP_KEY` (rotating it invalidates existing sessions
            and any `encrypted` cast — **do NOT rotate once `pos_integrations` /
            `delivery_integrations` hold real tokens**; it would render them undecryptable).
      - [ ] Stripe test keys: **do not bother rotating** — they're being replaced with live keys
            anyway. **Set the live keys directly in the Cloud dashboard, never paste them into a
            chat session**, or they land in a transcript too. Same rule for every secret above.
- [ ] **Stripe live mode**: swap to live keys, create live webhook + `STRIPE_WEBHOOK_SECRET`,
      have first restaurant (Marcos) complete **live** Connect onboarding, place one real
      end-to-end order and confirm application fee + payout land.
- [ ] **POS environment vars — `SQUARE_ENVIRONMENT=production` + `CLOVER_ENVIRONMENT=production`
      in Cloud.** Both default to `sandbox` in `config/services.php` and the API/OAuth hosts key
      entirely off them — unset, every connect and ticket push silently goes to sandbox hosts and
      real registers never see an order (the same silent-fallback class as the `MEDIA_DISK` item
      below). Also set the production `SQUARE_*`/`CLOVER_*` app creds + redirect URIs.
      `cloud-check.php` now checks all of these (added 2026-07-15). DEPLOY.md Step 5 documents them.
- [ ] **Onboarding/delivery keys in Cloud**: `CLAUDE_API_KEY` (AI menu import dies silently
      without it — kills the "free setup" pitch) and `GOOGLE_MAPS_API_KEY` (address capture +
      delivery quotes). Both now checked by `cloud-check.php`.
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
- **Base — PINNED (2026-07-15): 4% of the post-redemption food subtotal.** One sentence, rewards or
  no rewards: *we take 4% of what the customer actually pays for food.* Tax, tip, and the delivery
  fee are excluded. `OrderPlacement::prepare()` already computes on the food subtotal — the only
  change §10 brings is that redemption must reduce that subtotal **before** the fee line
  (`OrderPlacement.php:120`).
  - **Tips excluded — this is the industry norm, NOT a differentiator.** Verified 2026-07-15 against
    competitors' own documents. DoorDash's glossary defines subtotal as "the price of an order before
    taxes, commissions, fees, error charges, and tips"; Uber Eats' fee is "applied to order
    sub-totals"; ChowNow's Restaurant Agreement defines Subtotal as "excluding Fees, taxes, tip,
    delivery fees, and any additional fees." Every commission-type fee in this market already
    excludes tips. **Do not sell "we don't take a cut of tips" as a differentiator** — it's table
    stakes, and no competitor markets it because there is nothing to claim. The differentiator is
    **4% vs 15–30%**.
  - **But keep excluding them, deliberately.** Our own code routes tips to staff or the courier
    (`TipRecipient::forOrder()`), and third-party delivery passes the tip to Uber for the driver —
    a cut of a tip is a cut of a worker's money, never the restaurant's revenue. Precedent: DoorDash's
    2017–2019 model counted tips toward courier pay and cost it **~$30M+** across the DC ($2.5M),
    Illinois ($11.25M) and NY ($16.75M) AGs; the FTC's analogous case is **Amazon Flex ($61.7M)**.
    Delaware **HB 315** would bar card fees on the tip portion outright. This is a live legislative
    target — stay off it.
  - **Net-of-redemption is where we genuinely differ.** Uber applies its fee to "order sub-totals
    **before discounts**" — it charges on money the restaurant never collected. We charge on what
    they actually collect, so **Plateful absorbs 4% of every redemption and the restaurant funds
    96%**. Deliberate: it buys one simple rule with no gross/net special case, and it is a real,
    sayable edge — *"we charge on what you collect; they charge on what you would have."*
  - **What we cannot claim: that tips are fee-free.** Stripe's 2.9% + 30¢ is charged "per successful
    transaction" on the gross charge, tip included, and on direct charges the **restaurant** pays it
    ("application fees … are in addition to Stripe fees"). Square: "processing fees are taken out of
    the total amount of each transaction, including tax and tip." Toast: the restaurant pays its rate
    "on the gross amount of all card transactions." Nobody escapes this — the card rails can't split
    a tip from a sale. Tips are free of *our* cut, not of Stripe's. Say it that way.
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
- [x] `UpdateRestaurantFeeRequest` accepts `4`, capped at 15%
      (`MAX_PERCENT`, app/Http/Requests/Admin/SuperAdmin/UpdateRestaurantFeeRequest.php). The old
      0–100 fat-finger range was tightened 2026-07-15 with boundary tests (RestaurantFeeTest).
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
      is proven and only Clover is pending; they are in identical shape. Note (corrected
      2026-07-15): there are **three** credential-gated live-sandbox suites, not two —
      SquareLiveSandboxTest, CloverLiveSandboxTest, and UberDirectLiveSandboxTest. In CI all three
      skip, so a green CI run says nothing about *any* live integration; locally only Uber's runs
      (its creds are set).
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

## 3. Delivery dispatch (Phase 2) — **Uber Direct COMPLETE (2026-07-15); DoorDash Drive the launch provider, mostly built (2026-07-17)**
_Built end-to-end and verified against the real Uber sandbox: per-restaurant credentials, the
adapter, status webhooks, Places address capture, quote-before-payment, and auth/capture. The
customer now gets a committed fee and ETA before paying, and is only ever charged once a courier
actually exists. `DeliveryFeeStrategy` is wired and `DeliveryDispatcher::quote()` has a caller._

**DoorDash Drive is now the launch delivery provider** (Uber kept dormant). As of 2026-07-17,
Sessions 1, 4a, 4b, 2, 3 of the DoorDash plan are DONE (adapter, full money model, one-click
provisioning, webhooks) — full suite 848 green, JWT+quote and the §1 money model both verified live
against the sandbox. Remaining: Session 5 (refunds), Session 0/6 (prod access + go-live).

**Full plans:**
- **[docs/doordash-drive-implementation-plan.md](docs/doordash-drive-implementation-plan.md)** — the
  active plan (launch provider), with a per-session progress table at the top. **Read this first.**
- [docs/uber-direct-implementation-plan.md](docs/uber-direct-implementation-plan.md) — the Uber
  adapter (now dormant); carries the auth/capture decisions and the corrections the live API forced.

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

**DoorDash Drive — done 2026-07-17** (details + progress table in the DoorDash plan)
- [x] **Session 1** — `DoorDashProvider` (quote→accept→status→cancel), `DoorDashJwtService`
      (DD-JWT-V1), `DoorDashClient`, status map; registered in `AppServiceProvider`; default chain now
      `['doordash']`. Live sandbox: JWT auth + quote `HTTP 200`.
- [x] **Session 4a** — monthly cap ($249, restaurant-local), accounting columns
      (`platform_commission_cents`/`delivery_margin_cents`/`courier_fee_cents` + backfill),
      revenue-split reads true commission, `RevenueRole::DeliveryMargin`.
- [x] **Session 4b** — customer gross-up + central-billing recovery (`DeliveryMarkup`, gated on
      `isCentrallyBilled()`); §1 money model verified live to the cent.
- [x] **Session 2** — one-click umbrella provisioning (`DoorDashProvisioningService`) + enable/
      disconnect UX; DoorDash leads the admin card list.
- [x] **Session 3** — platform-secret webhooks (`DoorDashWebhookController`); `hasCourier()`
      generalized onto the `DeliveryStatus` enum so the deadline job + both webhooks are provider-agnostic.

**DoorDash Drive — remaining**
- [x] **Session 5 — DONE (verified in code 2026-07-23).** Refunds & cancellation shipped: two
      independent food-refund toggles (`pickup_refunds_enabled`/`delivery_refunds_enabled`, default
      OFF) + `refund_policy_reviewed_at`, `RefundCalculator`/`RefundPlan`, partial refunds with
      proportional commission reversal (`OrderTransition::applyRefund()` zeros commission/margin +
      `revenueSplits->reverse()`), Settings UI + dedicated onboarding step. Covered by
      DeliveryRefundTest + RefundCalculatorTest; full suite green (867). One go-live item folded into
      Session 6: confirm DoorDash's live cancel-fee response field (`DoorDashProvider::parseCancellation()`
      defaults to courier-fee-retained when silent). **Reader-side follow-ups NOT done** (tracked
      elsewhere): earnings partial-refund proration (§7), `charge.refunded` webhook (§11), a
      `refunded_cents` consumer (§8) — Session 5 shipped the refund engine, not these downstream hooks.
- [ ] **Session 0 / 6** — file the DoorDash production-access request (gated: certification + a live
      Zoom demo, no timeline — start early), then go-live (swap to prod creds, confirm the webhook
      secret + signature scheme in the DoorDash portal, keep the Uber adapter dormant).

**Before this can take real money** (full list in the plan)
- [ ] **A queue worker must be running.** Delivery dispatch and the auth/capture deadline are queued
      jobs on `QUEUE_CONNECTION=database`. Without a worker, authorized orders never dispatch AND
      never expire — holds sit on customer cards with nothing scheduled to release them. This is the
      one operational dependency with no in-code backstop. Worth a Sentry alert on
      `payment_state = 'authorized'` older than an hour.
- [ ] **DoorDash go-live env** (launch provider): set production `DOORDASH_DEVELOPER_ID` / `KEY_ID` /
      `SIGNING_SECRET` / `WEBHOOK_SECRET` once prod access is granted; register the webhook URL
      (`/webhooks/doordash`) in the DoorDash portal and **confirm the signature scheme** matches
      `DoorDashWebhookController::signatureIsValid()` (header + base64-vs-hex, currently assumed).
- [ ] Provision Plateful's **production** Uber account — it can currently mint only
      `direct.organizations`, not `eats.deliveries`. Only needed if Uber is un-dormanted; sandbox
      working says nothing about it.
- [ ] Rotate the production Uber Client Secret (exposed in a session transcript 2026-07-14).
- [ ] Restrict the Google Maps key to Places API (New) + the production server's IP.
- [ ] (Uber only) Each restaurant creates its own Uber webhook and pastes the signing key. DoorDash's
      webhook is platform-level (one secret), so this per-restaurant step does not apply to it.

---

## 4. Customer-ownership features (the "own your customers" payoff)
_Head start: a `restaurant_customer` pivot already stores per-restaurant order counters
(`total_orders`, `total_spent_cents`, `first/last_ordered_at`), but it holds no contact info
(email/phone live on `User`) and is surfaced only on the customer's own storefront loyalty view —
never in the tenant admin. So this is a partial data foundation, not a blank slate._
- [ ] **Loyalty → see §10.** Rewards are restaurant-owned (opt in/out, own rate, they fund
      redemption) and are the sharpest "own your customers" lever we have. The full state, the
      decided model, and the open questions live in **§10** — one section, so this doesn't drift
      into two conflicting accounts.
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

- [x] **Tighten fee validation range.** Done 2026-07-15: capped at 15%
      (`UpdateRestaurantFeeRequest::MAX_PERCENT`, now in `app/Http/Requests/Admin/SuperAdmin/`),
      with boundary tests in RestaurantFeeTest and a matching `max` on the fee input.
- [ ] **`refunded_cents` is written but never read.** `OrderTransition` sets it to the full
      `total_cents` on a refunded cancel (`OrderTransition::refundOnCancel()`) and no code consults it. It's the
      natural hook for the §7 partial-refund proration — wire it there, or drop the column if
      partials stay out of scope. (Since §8, an order cancelled while only *authorized* is voided
      instead and correctly leaves this at 0 — nothing was charged, so nothing was refunded.)
      **Audited 2026-07-15 and deliberately KEPT** — do not re-flag as dead; it's the §7 hook.
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
- [x] **Verified dead code — removed 2026-07-15** (re-verified zero-reference first): deleted
      `routes/settings.php`, `RequirePlatformHost` + its `'platform'` alias, `MenuItemReorderRequest`,
      `config('platform.admin_notification_email')`, the never-read `services.stripe.key` config line
      (the `STRIPE_KEY` env var stays — cloud-check still verifies it), 11 superseded net-zero
      migration files (the 7-migration Cashier chain, the 3-file `restaurant_signups`
      create/alter/drop set, and the 1% fee-default migration superseded by the 4% one), and dropped
      `orders.stripe_transfer_id` via a new migration. Also removed the unused `toBeOne`/`something()`
      scaffolding from `tests/Pest.php`.
      **Deliberately KEPT — do not re-flag:** `voided_at`/`captured_at`/`authorized_at` (payment
      audit trail on the auth/capture feature), `restaurants.suspension_reason` (written by
      `unmake:restaurant`, an audit field), `restaurants.delivery_provider_priority` (the
      per-restaurant lever for the §3 DoorDash chain — becomes real the day a second provider
      ships), and `refunded_cents` (above).
- [x] **Checkout rate limit** (2026-07-15). `POST /orders` had no throttle while every hit creates
      a real Stripe Checkout Session — a card-testing target the moment live keys exist. Now
      `throttle:10,1`, matching the throttled quote/address endpoints beside it.
- [x] **Stripe dispute webhook** (2026-07-15). `charge.dispute.created` now records a chargeback
      note on the order timeline (`OrderEvent`) and logs at error level so monitoring sees it —
      disputes were previously invisible. An admin *surface* for disputes is still open → §11.
- [x] **Test-suite stray-HTTP guard** (2026-07-15). Global `Http::preventStrayRequests()` in
      `tests/Pest.php` — an unfaked HTTP call now fails fast instead of silently hitting the
      network. The three live-sandbox suites re-allow real requests per-file. Flushed out that
      Inertia SSR was attempting a real render call per page test; SSR is now off in tests
      (`INERTIA_SSR_ENABLED=false` in phpunit.xml).
- [x] **CI matrix trimmed to PHP 8.4** (2026-07-15) — 8.5 was a flake source, not coverage;
      production runs 8.4. Re-add when an upgrade is planned. Pest in CI now runs with
      `memory_limit=512M`.
- [ ] **Tip *amount* routing is untested.** `TipRecipientResolutionTest` +
      `OrderPlacementTipRecipientTest` prove which recipient is *resolved*, but nothing asserts the
      tip dollars actually flow/attribute to that recipient (staff vs. courier) — a routing
      regression on a money path would pass CI.
- [ ] **POS token expiry mid-push is untested.** OAuth-service refresh is covered
      (CloverOAuthServiceTest etc.), but no test proves an expired/401 token *during*
      `PushOrderToPos` triggers refresh-and-retry rather than a spurious permanent failure.

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

## 10. Loyalty — restaurant-owned rewards (earn path shipped, spend path missing)
_Surfaced 2026-07-15 by codebase audit; model decided 2026-07-15._

**Current state.** The earn path is shipped and tested: `LoyaltyService::awardForOrder()` fires on
the transition to `Completed` (`OrderTransition.php:51`), credits
`floor(subtotal_cents / 100) × points_per_dollar`, is idempotent via `orders.awarded_loyalty_points`,
skips guest orders, and is surfaced read-only at `/account/loyalty` (`LoyaltyController`).
**`awardForOrder()` is the only method on the service** — there is no debit, redeem, or spend path
anywhere in `app/`. Grepping "redeem" repo-wide returns two hits, both marketing copy.

**The liability is currently zero — this is the free moment to build it.** `loyalty_points` has
**0 rows**: no customer has ever earned a point (verified against the live DB 2026-07-15). Nothing
to grandfather, nothing to strand, no balance to honor. Every decision below gets more expensive the
day after the first real order.

### Model — DECIDED 2026-07-15
Rewards are a **tool the restaurant owns**, not a platform program. The pitch is that it's a lever to
pull customers off DoorDash; a restaurant that doesn't want it should never see it.
- **Opt-in / opt-out per restaurant, completely.** Off means no earning, no balance, no loyalty UI
  anywhere on that storefront — not a disabled tab.
- **The restaurant sets its own earn rate.** Not a platform constant.
- **Redemption comes out of the restaurant's pocket.** They fund the discount; it's their lever and
  their margin.
- **Plateful's 4% is charged on the POST-redemption food subtotal** — one rule, rewards or no
  rewards: 4% of what the customer actually pays for food. **Tax, tip and delivery stay excluded**
  (§1, unchanged — the tip exclusion is a deliberate differentiator, not an oversight).
  - *Stated so nobody "corrects" it later:* because the fee follows the discounted subtotal,
    **Plateful absorbs 4% of every redemption and the restaurant funds the other 96%** ($50 order,
    $5 redeemed → fee $1.80 not $2.00; the reward costs the restaurant $4.80). That small co-funding
    is the deliberate price of one simple rule, chosen over a gross/net special case.
  - *Implementation:* redemption must reduce the food subtotal **before**
    `OrderPlacement::prepare()` computes `$applicationFeeCents` (`OrderPlacement.php:120`). Get the
    ordering wrong and the fee silently reverts to gross. `StripeCheckoutTest` already pins
    tax/tip exclusion; add the redemption case beside it.

This also **resolves the `Terms.vue:104-112` contradiction** flagged by the audit: the Terms already
say "the terms, value, and availability of rewards are set by each Restaurant," which was false —
`points_per_dollar` is a platform-wide constant (`config/platform.php:108`, value `1`) that no
restaurant can touch, and every restaurant earns whether it wants to or not. The decided model is
what the Terms already describe. Build it and the Terms become true.

### Open — decide before building
- [ ] **Redemption mechanism.** Dollar-off at checkout, free item at a threshold, or tiers? Drives
      everything below.
- [ ] **Rate storage — mirror the fee's existing pattern.** Add `restaurants.loyalty_enabled` +
      `restaurants.loyalty_points_per_dollar`, and demote `platform.loyalty.points_per_dollar` to the
      **default for new restaurants at creation**, exactly as `default_application_fee_percent`
      already governs fees (§1: "only governs NEW restaurants at creation time; existing rows keep
      their stored rate"). Same shape, same grandfathering story, no new concept.
- [ ] **A fully-points-paid order must not produce a $0 or negative charge.** With the fee on the
      post-redemption subtotal, redeeming the whole order drives the subtotal — and our fee — to $0.
      If tax and tip are also 0 there is nothing to charge, and Stripe rejects sub-minimum amounts
      (~50¢); `application_fee_amount` must also never exceed the charge. Decide the floor (cap
      redemption at some % of the order? require a minimum charge?) before the mechanism is built,
      not when a PaymentIntent fails in production.
- [ ] **Add a ledger.** `loyalty_points` is a single `points` integer per `(user_id, restaurant_id)`
      (`2026_05_18_170004_create_orders_tables.php:45-52`) — a balance with **no transaction
      history**. Earning-only survives that; spending does not. Without rows you cannot answer "where
      did my points go", reverse a mistake, or settle a dispute. It also **races under concurrent
      redemption** — `awardForOrder()` already reaches for `lockForUpdate`, which is the tell. A
      redemption path probably wants an events table with the balance derived or reconciled — the
      same shape as `fee_distributions` in §7. Cheapest to add now, at 0 rows.
- [ ] **Clawback on refund.** Points are awarded at `Completed` and **never reversed** — nothing in
      `OrderTransition` or `DeliverySettlement` touches them on cancel or refund, so a
      completed-then-refunded order keeps its points. Free points for an order that earned nothing.
      Same family as §7's partial-refund proration and §8's `refunded_cents`.
- [ ] **Expiry / breakage.** The Terms mention forfeiture on account termination; nothing else
      expires. If the restaurant funds redemption, expiry is *their* lever too — so it likely belongs
      next to the rate, not as a platform rule.

### Build (once the above are settled)
- [ ] Gate the earn path: `awardForOrder()` currently fires for **every** restaurant with no check.
- [ ] Gate the surface: `/account/loyalty` (`routes/storefront.php:113`) is ungated and
      `AccountTabs.vue:41-43` shows a "Loyalty" tab unconditionally. Both must disappear when off.
- [ ] Tenant-admin UI to toggle rewards and set the rate.
- [ ] The redemption path itself + the ledger.

**Interim — DONE 2026-07-15:** the marketing/legal copy no longer promises redemption.
`Welcome.vue`'s "Redeem at the place you earned it" is now "Rewards that stay local", and the
Terms §6 now say points are *earned* with the restaurant and that ways to use them "are determined
by each Restaurant as they are made available" — true today (earn-only) and still true under the
decided §10 model.

---

## 11. Launch hardening — decisions surfaced by the 2026-07-15 audit
_Gaps found auditing launch readiness beyond §0/§3. None blocks the first test order; all matter
before onboarding restaurants at volume. The quick code fixes from the same audit are already
shipped and logged in §8 (checkout throttle, dispute webhook, cloud-check coverage)._

- [ ] **Email verification is not actually enforced.** `User` does not implement
      `MustVerifyEmail`, so the `verified` middleware (routes/storefront.php) silently no-ops and
      Fortify never sends the verification mail. Order confirmations go to unverified addresses.
      Decide: require verification for customers (adds checkout friction) or drop the dead
      `verified` middleware and own the decision explicitly.
- [ ] **Dispute admin surface.** `charge.dispute.created` now lands on the order timeline + error
      log (§8), but no admin UI lists open disputes or their evidence deadlines. Decide the surface
      (super-admin list? tenant banner?) — a missed deadline is an auto-lost chargeback.
- [ ] **Restaurant go-live checklist.** `Restaurant::scopePublic()` requires only
      `status=Active` + `is_active` — a restaurant can be listed with Stripe onboarding incomplete
      (checkout then hard-fails after the customer builds a cart) and zero hours rows (= silently
      open 24/7, see §9). Add an activation gate or an explicit checklist in the admin: Stripe
      enabled, hours set, delivery settings chosen, menu non-empty.
- [ ] **`pending_checkouts` / `delivery_quotes` grow unbounded.** Each pending checkout stores a
      full order snapshot; nothing prunes abandoned rows and nothing is scheduled at all (the
      scheduler runs but is empty). Add prune jobs once real traffic exists — noted in DEPLOY.md
      "Consider adding later".
- [ ] **Stripe event-level idempotency + more event types.** Order materialization is idempotent
      via `PendingCheckout` consumption, but there's no dedup on Stripe `evt_` ids and no handlers
      for `charge.refunded` / `payment_intent.*` reconciliation. Fine for launch; revisit with the
      §7 refund work.

---

## Suggested sequence
1. **§0 launch blockers** + **§1 pricing** (parallel; both small, both gate revenue/story).
2. ~~**§2a foundations**~~ — done.
3. ~~**§2b/2c Square injection + pickup**~~ — Square + Clover adapters done; catalog matcher open.
4. ~~**§3 delivery**~~ — Uber Direct complete 2026-07-15. Both halves of the strategy ("get the
   order to the kitchen" and "deliver it") now have a shipped path.
5. **Next: §0 launch blockers.** The build has outrun the launch prep — delivery is done and Stripe
   is still in test mode. §0 + §3's "Before this can take real money" list are what stand between
   this and a paying restaurant. The **§11 hardening decisions** (email verification, dispute
   surface, restaurant go-live checklist) come before onboarding restaurants at volume.
6. **§4 customer ownership** to make the pitch real; **§5 calculator** once pricing is locked.
   **§2d printer** widens the addressable set; **§6 onboarding automation** is an ongoing
   friction-reducer.
7. **§10 loyalty** — model decided (restaurant-owned: opt in/out, sets its own rate, funds its own
   redemptions; our 4% charged on the post-redemption subtotal, per §1). Two things make it
   time-sensitive: the marketing site already sells redemption as shipped, and `loyalty_points` is
   still at **0 rows**, so the ledger and the opt-in default are free to get right today and
   expensive the day after the first real order. What's left is product shape, not pricing.

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
