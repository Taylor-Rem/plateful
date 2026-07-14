# Uber Direct — Implementation Plan

Drafted 2026-07-14. Covers §3 of [todo.md](../todo.md) (delivery dispatch), Uber Direct half.
DoorDash Drive follows the same shape once its certification clears — see §9 below.

**Strategy in one line:** the customer gets a real, committed delivery fee and ETA *before* they're
charged, because the quote gates checkout instead of trailing it.

Sections are numbered in **execution order**. §0 is the decision log.

---

## 0. Decisions locked (2026-07-14)

### Account model: **per-restaurant Uber Direct accounts**
Each restaurant signs up at `direct.uber.com` and Plateful stores their `client_id` /
`client_secret` / `customer_id` encrypted per-tenant. Uber bills the restaurant directly.

**Rationale.** This mirrors both existing patterns — `pos_integrations` for POS, and Stripe direct
charges where the restaurant is merchant of record. The rejected alternative (Plateful holds one
umbrella account and rebills) fails on:
- **Economic nexus.** Utah's threshold is $100k gross sales, transaction-count test removed
  2025-07-01. Grossing delivery revenue through Plateful's books crosses that threshold in far more
  states, far sooner, on revenue that earns nothing — triggering registration/filing obligations
  that per-restaurant avoids entirely.
- **Revenue character.** Umbrella turns part of Plateful's revenue from a software fee into delivery
  service *resale*, with a different tax character and likely resale-certificate paperwork per state.
- **Credit risk.** Umbrella means fronting Uber's costs and carrying float on someone else's bill.

**Marketplace-facilitator risk is NOT a factor either way** — Utah's definition explicitly excludes
"a person who only provides payment processing services or, as of July 1, 2020, facilitates sales
for restaurants." Plateful is the Toast *Online Ordering* shape (restaurant's own branded
storefront), not the Toast *Local* shape (cross-tenant consumer marketplace); Toast is a facilitator
only for the latter. This would only change if Plateful ever built a shared "restaurants near you"
discovery surface.

**Cost accepted:** every restaurant does their own Uber signup, and because Uber Direct uses
`client_credentials` (not a redirect flow), they paste credentials into an admin form rather than
clicking "Connect."

### Pricing: pass-through by default, guaranteed-fee opt-in
- **Pass-through** — the customer pays the live Uber quote. The fee is what delivery actually costs.
- **Guaranteed fee** — the restaurant advertises a flat number and absorbs the delta. No cap on
  restaurant exposure in v1; add one when the measured drift (§7) says it's needed.
- `customer_delivery_fee_cents_max` is **dropped** — it was never read anywhere.
- `delivery_fee_cents` **stays**, meaning "the restaurant's own price" for self-delivery and
  guaranteed mode.
- Delivery is a **separately stated, untaxed line**, behind a flag pending CPA confirmation (§11).

Today's behavior is absorb-by-accident in both directions: `OrderPlacement.php:85` charges a flat
admin-set fee decided before any provider is contacted, and the real quote never revises
`orders.delivery_fee_cents`. A restaurant charging $4.99 against a $9.20 quote silently eats $4.21;
on a close-in order they silently pocket the difference. Nobody chose either outcome.

### `DeliveryFeeStrategy` keeps two cases, not three
`PassThrough` and `Absorb`. **`Split` is dropped** alongside `customer_delivery_fee_cents_max`.

Two products, two cases. "Guaranteed fee" *is* `Absorb` — the restaurant absorbs the delta above
whatever it advertises in `delivery_fee_cents`. Free delivery isn't a third mode, it's
`delivery_fee_cents = 0` down the same code path.

`Split` implies a splitting rule that this plan explicitly declines to define (no exposure cap in
v1). An enum case with no rule behind it is exactly what `customer_delivery_fee_cents_max` was, and
it's how both ended up unreachable. If a cap ever ships, that's the moment `Split` means something
and can be added with a real definition behind it.

### Quote timer: pass-through only
A 15-minute countdown reading **"your delivery fee is guaranteed for 14:36."**

The quote locks **price** and nothing else — Uber only searches for a courier when the delivery is
*created*, and that search can fail against a perfectly valid, unexpired quote. So the timer must
not imply availability; it can't back that claim. In guaranteed-fee mode the customer's price can't
change, so there's nothing to count down — re-quote silently in the background instead. (Revisit if
absorbing restaurants ever get an exposure cap: a re-quote could then withdraw delivery, which *is*
customer-visible.)

Availability is guaranteed by auth/capture (§8), not by the timer.

### Quote drift across the Stripe redirect: freeze, clamp, measure
Payment is a **redirect to Stripe-hosted Checkout**, not an inline sheet, so the customer can sit on
Stripe's page indefinitely. Checkout Sessions default to 24h and Stripe's *minimum* `expires_at` is
30 minutes — already longer than Uber's 15-minute quote. The quote can therefore always expire
mid-payment. There is no arrangement of timers that prevents this.

So: **freeze the fee at session creation**, clamp the session to Stripe's 30-minute floor to bound
the window, and let the restaurant carry the drift.

Explicitly rejected: re-quoting on return. That either eats the delta anyway or bounces a customer
who has already paid — the exact failure this whole plan exists to prevent.

Don't guess at the exposure, measure it: `delivery_assignments` already has both `quote_fee_cents`
and `actual_fee_cents`. Populate both from day one, and the cap decision gets made against real
numbers instead of a number someone invented.

### Tips: pass through to the courier — not a choice
Uber's merchant terms require that a customer tip for the delivery person **must** be passed to the
delivery person. Mechanics: tips can only ever be *increased* post-creation via Update Delivery,
never decreased; a tip set in error requires cancel-and-recreate, and only pre-dispatch. We collect
at checkout and set at create time, so this is fine — and post-delivery tipping is supported.

`SelfDeliveryTipRecipient` still governs self-delivery. On an Uber order that choice disappears;
checkout copy should say "tip your driver," not a generic "tip."

Money flow: customer → restaurant's connected Stripe account → restaurant's Uber Direct account pays
Uber including tip → Uber pays courier. Net-zero for the restaurant, but the tip transits their
books. **The 4% application fee stays on food subtotal only** (`OrderPlacement.php:90`) — unchanged.

### Self-delivery disclaimer
Self-delivery checkout carries an explicit "the delivery charge is not a tip paid to your driver"
line. Domino's charged $2.50, kept it, and lost a motion to dismiss under the Massachusetts Tips Act
partly because its disclaimer was arguably *insufficient* — the court noted $2.50 is about what a
customer would have tipped, so a reasonable customer would read the charge as the tip and skip
tipping. The liability is the restaurant's (employment law, them and their driver), but Plateful
renders the screen. Costs one line of copy; it's a selling point.

Uber pass-through mode needs no disclaimer — the fee *is* the courier cost and the tip *does* reach
the courier, so there's no gap between label and money. Guaranteed-fee mode is benign: the number is
fictional in the customer's favor, but Uber still pays the courier and the tip still reaches them.

**Follow-up:** audit what cases `SelfDeliveryTipRecipient` actually defines. If it permits routing
tips to the restaurant rather than the driver, state Tips Acts have opinions about that.

### Address entry: Google Places via a **backend proxy**, not a browser key
Geolocation dropped: it returns coordinates, not addresses, and never gives you "Apt 4B."
Free-text rejected: it produces exactly the failures Uber's docs warn about.

Uses the existing Google Cloud project with one more API enabled — but note the `google` block in
`config/services.php` is an **OAuth client** (`client_id`/`client_secret`/`redirect`) and cannot
call Places. This needs a **separate Maps API key**, and the decision is where it lives:

**Proxy Places through the backend** with a server-side, IP-restricted key. Two reasons, both from
this app being multi-tenant:
- **Referrer restrictions don't scale to custom domains.** A browser key is protected only by its
  HTTP-referrer allowlist, and every custom domain onboarded is a new entry — a permanent
  operational tax on a feature the tenancy layer is already designed for.
- **`PlaceAutocompleteElement` will look like Google, not like the restaurant.** Storefronts render
  brand-colored via `BrandColors::paletteFor`; the Google widget's styling surface is narrow. A
  dropdown we own inherits the palette for free.

A server-side key also just matches how every other credential in this app is handled. Session
tokens still work through a proxy — generate client-side, pass through, keep session-based billing.

**Cost accepted:** roughly a day building the dropdown ourselves instead of dropping in a web
component. (Also note the legacy `Autocomplete` widget was deprecated for new customers in March
2025, so the drop-in path would have been `PlaceAutocompleteElement` regardless.)

**Unit/apt is a separate field** — Places won't reliably return it.

### Quote storage: a `delivery_quotes` table
The quote is taken by an AJAX call and consumed by a later checkout POST, so it has to survive
between the two — and it cannot come back from the client, because it's money.

A table rather than a cache entry, because it does three jobs a cache does badly: it holds the
**exact address payload** for the byte-identical replay rule (§2), it's **money** and wants an audit
trail, and the **drift measurement** above needs it to outlive the request. It also matches how the
rest of the app behaves — `OrderEvent`, the revenue ledger, `PendingCheckout` all favor a durable
trail.

Referenced by opaque id from the `PendingCheckout` payload; pruned on the same schedule as pending
checkouts.

---

## 1. Fix the live `delivery_enabled` bug — *before anything else*

Not part of the feature; a bug the feature would otherwise inherit and hide.

`RestaurantData` exposes only `deliveryFeeCents`, so `delivery_enabled` never reaches Vue, and
`Checkout.vue:260` renders the Delivery toggle unconditionally. `CheckoutRequest` doesn't check it
either. **Every storefront offers delivery today**, including restaurants with delivery switched
off: the customer is charged `delivery_fee_cents`, the order places fine, and
`DispatchDeliveryForOrder.php:52` returns silently on the empty provider chain without even logging
an event. The customer pays for a delivery nobody dispatches and nobody finds out.

Unlike the provider-chain default (§3), this one fires *today*. Fix it as its own commit ahead of
the feature so it's legible in the history rather than buried in the §6 diff:

- `deliveryEnabled` onto `RestaurantData`.
- Gate the Delivery toggle in `Checkout.vue`.
- Reject delivery orders in `OrderPlacement::prepare()` when delivery is off — same place and shape
  as the `restaurant_closed` guard at `OrderPlacement.php:50`, which covers the internal `place()`
  path too.
- `OrderEvent::note()` instead of the silent return, so an owner can see *why* nothing dispatched.

## 2. Credentials + token service

- `config/services.php` gets an `uber_direct` block following the Square/Clover shape:
  `client_id`, `client_secret`, `customer_id`, `environment` (test|production).
- **New `delivery_integrations` table** mirroring `pos_integrations`: encrypted credentials,
  `status`, `last_error`, unique `(restaurant_id, provider)`. This is the piece POS has and delivery
  lacks — `DeliveryProvider::supports()` currently just reads `delivery_enabled` off the restaurant,
  which won't survive real per-tenant credentials.
- `UberDirectTokenService`: `client_credentials` grant against `https://auth.uber.com/oauth/v2/token`,
  scope `eats.deliveries`. Cache the token, refresh proactively before expiry.
  **Simpler than Square/Clover** — machine-to-machine, no refresh-token rotation, no callback route.
- Admin UI: **credential entry form only** (paste `client_id`/`client_secret`/`customer_id`),
  alongside the existing POS Connect surface. The seven delivery *behavior* flags wait for §7 — see
  "Admin scope" below.

## 3. The adapter

`UberDirectClient` (HTTP, host + pinned API version) and `UberDirectProvider` implementing the
existing `DeliveryProvider` contract (`quote` / `create` / `status` / `cancel`). Registered in
`AppServiceProvider` next to `SelfDeliveryProvider`.

Quote response fields we care about: `id`, `fee`, `dropoff_eta`, `dropoff_deadline`, `duration`,
`pickup_duration`, `expires` (15 min). `DeliveryQuote` grows fields to carry them plus the exact
address payload — a contract change that ripples into `SelfDeliveryProvider`.

**Two documented landmines:**
- Persist the *exact* address payload used for the quote and replay it **byte-identical** on create,
  or Uber returns `delivery location changed`.
- Sending lat/lng more than 1km from the stated address makes Uber silently override the coordinates
  with its own geocoding. We send address-only and let Uber geocode.

**Also fixes a second live bug:** `DeliveryDispatcher.php:27` defaults the provider chain to
`['doordash','uber']`, so any restaurant flipped to third-party mode *today* gets
`provider_unsupported` and a permanently failed job. Inert only because nobody's turned it on.

**Verification:** an opt-in `UberDirectLiveSandboxTest` mirroring `CloverLiveSandboxTest` — skips
cleanly without credentials so CI stays offline and deterministic. Unit tests use `Http::fake`
regardless; that's the right shape either way.

## 4. Status webhooks — *moved ahead of auth/capture*

Uber pushes courier status. `delivery_assignments` already carries the `[provider, external_id]`
index for the lookup — the schema anticipated this. New webhook route + signature verification,
driving `DeliveryStatus` transitions and customer notifications.

**Why this moved:** §8 captures payment "once a courier is confirmed," but nothing in the
create-delivery response says that — Uber returns `pending` and the courier lands later, via this
webhook. As originally sequenced, auth/capture depended on a signal that didn't exist yet.

## 5. Address capture

Places autocomplete on the checkout address field (backend proxy + our own dropdown, per §0) plus a
separate unit/apt field. The formatted result lands in the existing `orders.delivery_address` JSON
snapshot, which becomes the **single source of truth** for both quote and create (see the
byte-identical rule in §3). Assembled once, not twice.

Note: once a courier accepts, **addresses cannot be changed** — cancel and recreate. That constrains
what customers may edit post-checkout.

## 6. Quote at checkout — the real rework

**The heart of the change.** Today the quote happens post-payment inside `DispatchDeliveryForOrder`,
which is exactly why a customer can be charged before we know delivery is possible.

Target flow:
1. Cart — cheeseburger + fries, $10.00
2. Customer selects **delivery** → enters address (autocomplete + unit field); offer to save it if
   signed in
3. **Live quote** → persisted to `delivery_quotes`, fee displayed, total becomes $18.00 → timer
   starts (pass-through mode only)
4. Tip → customer rounds up to $20
5. Tax → charge

Quote failure means delivery isn't offered at all — **that's the out-of-range check for free.** No
geocoding, no radius math, no delivery-zone table. Uber caps around 10 miles but sets limits
per-market and coverage varies with driver density, so there's no constant worth hardcoding: let the
quote be the oracle. Consequence: delivery availability is per-address at checkout, not a static
restaurant flag, and the fee is unknowable until an address exists ("enter your address for delivery
pricing," then a firm number).

On timer expiry: re-quote, then either show the new fee or tell them delivery is no longer
available. Restaurant hours already gate this upstream (`OrderPlacement.php:50`), so
after-close orders are rejected before any of it.

Touches `OrderPlacement.php:85`, `Checkout.vue`, and finally gives `DeliveryDispatcher::quote()` a
caller — closing the dead-code item in todo.md §8.

## 7. Pricing + kitchen ETA + the delivery settings page

Wire `DeliveryFeeStrategy` for real (it is currently defined, cast, and read by **nothing** —
`Absorb` and `Split` are unreachable). Drop `Split` and `customer_delivery_fee_cents_max`. See §0.

`prep_time_minutes` on restaurants, default **5**, restaurant-adjustable on the fly. Uber's
`dropoff_eta` assumes the food is ready *now*. Without prep time the customer-facing ETA is wrong by
the length of the ticket, and the courier idles in the lobby. This number does double duty: it feeds
Uber's pickup-ready time and it makes the customer promise honest. Per-hour scheduling deferred —
flat number first; see whether owners actually tune it.

**Admin scope.** Seven delivery columns have no UI and no validation today —
`delivery_enabled`, `delivery_mode`, `delivery_provider_priority`, `delivery_fee_strategy`,
`self_delivery_tip_recipient`, `delivery_fallback_action`, `customer_delivery_fee_cents_max`. Only
`delivery_fee_cents` is editable (`Settings.vue:197`); an owner literally cannot turn delivery on
from the UI. The real Delivery Settings page lands **here**, not in §2, because building it earlier
means guessing its shape before the checkout rework tells you what it needs (the enum collapse and
`prep_time_minutes` both land in this section). Build it once, when it's known.

For proving the sandbox quote in §2–3, set the flags by tinker. That's what "independently
shippable" is for.

## 8. Auth/capture — *last, and carefully*

**There is no bare PaymentIntent.** The flow is a Stripe-hosted Checkout Session
(`StripeConnectService.php:34`) the customer is *redirected* to. Manual capture is settable via
`payment_intent_data.capture_method`, but it cascades:

- `CheckoutController.php:137` bounces the customer back to the cart unless
  `payment_status === 'paid'`. Under manual capture a *successful authorization* reads `unpaid`, so
  this check breaks the happy path until it's rewritten.
- Orders currently only exist once paid. Auth/capture introduces an authorized-but-not-captured
  order, and `OrderStatus` has no case for it.

Target: **authorize** at checkout → create the Uber delivery → **capture** only once a courier is
confirmed (via §4's webhook). If Uber can't find a driver, void the authorization: the customer sees
a hold drop off, never a charge-and-refund.

**The POS push gates on courier confirmation too.** `OrderPlacement.php:277-290` currently queues
the POS push and the delivery dispatch together. Cooking a meal for an order about to be voided is
worse than a ticket that prints a minute late — courier assignment typically resolves well inside
`prep_time_minutes`. Both the push and the capture hang off the same trigger: *the delivery is real
now*.

The failure mode to design for is not the slow courier, it's the **search that hangs** and strands
the order with no ticket and no charge. Put a deadline on it: no courier within N seconds → void,
notify, done. Fail closed and loudly.

This is the actual mechanism behind "never charged for a delivery that didn't happen," and it works
at the only moment availability is genuinely knowable. Courier assignment happens *after* payment
regardless of what any timer said — the timer and the courier search are unrelated events.

Quote drift over the redirect window is settled in §0: freeze at session creation, clamp the session
to 30 minutes, record `quote_fee_cents` vs `actual_fee_cents`, decide on a cap later with data.

## 9. DoorDash Drive (later)

Same contract, same dispatcher chain, one line in `AppServiceProvider`. Deliberately second: Uber
Direct's sandbox is self-serve on account creation, while Drive gates *production* access behind a
business agreement and integration certification. Building Uber first means shipping delivery to
real restaurants while DoorDash paperwork runs in parallel, instead of finished code idling.

Worth having both eventually: coverage differs by market and address, `DeliveryFallbackAction`
already has `try_next_provider`, and two live integrations means routing by cheaper quote and not
being captive at renegotiation.

---

## 10. Sequencing

1. **§1** — the `delivery_enabled` fix. Self-contained, depends on nothing, fixes a live bug.
2. **§2–3** — credentials + adapter. Independently shippable; prove a real sandbox quote before
   touching checkout. Flags set by tinker.
3. **§4** — webhooks. Ahead of auth/capture, which needs the courier-confirmed signal.
4. **§5–7** — the checkout rework. Wants to land together.
5. **§8** — auth/capture. Separable; touches shipped payment code, so on its own.

**Getting sandbox credentials** (~10 min, self-serve): `direct.uber.com` → log in or create an Uber
account → accept the Uber Direct Terms + API Terms of Use → skip billing (only required for
*production*) → **Management → Developer** for Client ID / Client Secret / Customer ID. A test
sandbox is provisioned automatically and the dashboard marks test mode; test credentials create no
real deliveries.

**Operational note:** delivery dispatch is a queued job and `QUEUE_CONNECTION=database`, so a worker
must be running (`composer run dev` includes `queue:listen`) or nothing dispatches — same caveat as
the POS pushes.

---

## 11. Open questions — for a CPA, before launch

None of these block the build; all should be settled before real money moves.

- [ ] **Is a separately stated delivery fee taxable in Utah?** Secondary sources consistently say
      separately-stated delivery charges are not subject to sales tax while bundled ones are —
      pointing toward not taxing it. **Not verified against the Tax Commission's own publication.**
      Behavior ships behind a flag until confirmed.
- [ ] **Is Plateful's own 4% fee taxable?** Utah taxes prewritten computer software; whether a
      hosted-platform transaction fee counts is a real question. Independent of delivery.
- [ ] **Does the restaurant carve-out hold in expansion state #2?** The marketplace-facilitator
      exclusion for restaurant facilitation is Utah-specific (Utah Code, effective 2020-07-01).
      Other states may not have it, and that changes who remits.
- [ ] Utah layers a **restaurant tax** on prepared food — confirm existing tax logic reflects it.
