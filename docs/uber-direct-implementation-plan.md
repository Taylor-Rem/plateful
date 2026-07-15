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

Behaviour before §6 was absorb-by-accident in both directions: `OrderPlacement` charged a flat
admin-set fee decided before any provider was contacted, and the real quote never revised
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
books. **The 4% application fee stays on food subtotal only** (`OrderPlacement.php:118`) — unchanged.

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
  as the `restaurant_closed` guard at `OrderPlacement.php:53`, which covers the internal `place()`
  path too.
- `OrderEvent::note()` instead of the silent return, so an owner can see *why* nothing dispatched.

## 2. Credentials + token service — **built 2026-07-14**

- **New `delivery_integrations` table** mirroring `pos_integrations`: encrypted credentials,
  `status`, `last_error`, unique `(restaurant_id, provider)`. This is the piece POS has and delivery
  lacks — `DeliveryProvider::supports()` currently just reads `delivery_enabled` off the restaurant,
  which won't survive real per-tenant credentials.
- `UberDirectTokenService`: `client_credentials` grant against `https://auth.uber.com/oauth/v2/token`,
  scope `eats.deliveries`. **Simpler than Square/Clover** — machine-to-machine, no refresh-token
  rotation, no callback route.
- Admin UI: **credential entry form only** (paste `client_id`/`client_secret`/`customer_id`),
  verified against Uber's token endpoint before saving so a typo fails in front of the person who
  can fix it. The seven delivery *behavior* flags wait for §7 — see "Admin scope" below.

### Corrections from the live sandbox

Four things the plan asserted from the docs that the real API contradicted:

- **No `environment` config key.** Copied from the Square/Clover shape, but Uber Direct serves test
  and production from the *same* host (`api.uber.com/v1/customers/{customer_id}/`). Test mode is a
  property of the credentials, set by a dashboard toggle — there is no host to select, so the key
  would have selected nothing.
- **Tokens live 30 days, and the grant is rate-limited to 100 requests/hour.** Caching is therefore
  a correctness requirement, not an optimization: re-minting per request breaks the integration
  under any real load. Tokens are stored on the integration row and re-minted 24h before expiry.
- **Uber's error taxonomy is finer than documented**, and worth mapping precisely because these
  strings are what an owner reads on the settings screen:

  | condition | HTTP | `error` |
  |---|---|---|
  | unknown client id | 401 | `invalid_client` |
  | bad client secret | 403 | `access_denied` |
  | account lacks the scope | 400 | `invalid_scope` |

- **Live tests must defer their skip check into the test lifecycle.** `.env` is loaded when the
  application boots, in `setUp()` — long after Pest collects the file. Reading env at the top level
  of a test file yields null *even when the credentials are set*, so the test skips
  unconditionally. `CloverLiveSandboxTest` had exactly this bug and had never once run.

### Verified live 2026-07-14

`UberDirectLiveSandboxTest` passes against the real Uber sandbox: a token mints with the
`eats.deliveries` scope, is stored and reused rather than re-requested, and a **real priced quote**
comes back for a real pair of addresses. That is the gate this plan puts in front of the checkout
rework — §2 and §3 stand on verified ground.

Getting there surfaced a trap worth remembering: a *provisioning* problem presents as
`invalid_scope`, which reads exactly like a code bug. If it recurs, the tell is that the deliveries
endpoint replies *"requires at least one of the following scopes: eats.deliveries"* while the auth
endpoint refuses to mint that very scope — that combination means the **account**, not the code.
Fix it at direct.uber.com by completing setup and accepting the API Terms of Use (separate from the
Uber Direct Terms). `UberDirectTokenService` maps `invalid_scope` to exactly this diagnosis.

> **Known, for launch: Plateful's own PRODUCTION Uber account is not provisioned.** Production
> credentials were briefly in `.env` by mistake on 2026-07-14, and while there they proved they can
> mint only `direct.organizations` — never `eats.deliveries`. So the sandbox working says nothing
> about production; the same wall is waiting there. Provision it before go-live rather than
> discovering it on the first real delivery. (Also from that mistake: **rotate that production
> Client Secret** — it and a minted token were exposed in a session transcript.)

## 3. The adapter — **built 2026-07-14**

`UberDirectClient` (host + pinned API version) and `UberDirectProvider` implementing the existing
`DeliveryProvider` contract (`quote` / `create` / `status` / `cancel`). Registered in
`AppServiceProvider` next to `SelfDeliveryProvider`.

Quote response fields we care about: `id`, `fee`, `dropoff_eta`, `dropoff_deadline`, `duration`,
`pickup_duration`, `expires` (15 min). `DeliveryQuote` grew fields to carry them plus the exact
address payload — additive, so `SelfDeliveryProvider` was untouched.

**Two documented landmines, both handled:**
- Persist the *exact* address payload used for the quote and replay it **byte-identical** on create,
  or Uber returns `delivery location changed`. `UberDirectAddress` is the single encoder, with fixed
  key order, and the quote carries its payload forward into create rather than re-encoding.
- Sending lat/lng more than 1km from the stated address makes Uber silently override the coordinates
  with its own geocoding. We send address-only and let Uber geocode — asserted by a test.

**Also fixes a second live bug:** `DeliveryDispatcher.php:37` defaulted the provider chain to
`['doordash','uber']`, so any restaurant flipped to third-party mode *today* got
`provider_unsupported` and a permanently failed job. Now defaults to `['uber']`; add `doordash` when
§9 lands, not before.

### Mind which Uber API you're reading

There are **two** delivery APIs and their docs sit side by side:

| | endpoint | keyed on | addresses |
|---|---|---|---|
| **Direct** (ours) | `/v1/customers/{customer_id}/…` | `customer_id` | JSON *string* |
| Eats | `/v1/eats/deliveries/…` | `store_id` | Google Place ids |

They take different fields and different identifiers. The scope gating **both** is confusingly named
`eats.deliveries`, so the scope name is not a signal for which one you're on. The dashboard hands out
a `customer_id`, which is the tell: we're on Direct.

Two shapes worth knowing, both verified against Uber's own examples:
- `pickup_address` / `dropoff_address` are **JSON-encoded strings, not objects** —
  `"{\"street_address\":[\"20 W 34th St\",\"Floor 2\"],…}"`.
- `fee` is already in **cents**. No conversion.

### The courier tip — resolved, and it hid a fee bug

**The field is `tip`** (integer, cents) on create. Settled not by experiment but by reading the
`DeliveryReq` schema in **Uber's own OpenAPI spec**, which ships inside their SDK at
`uber/uber-direct-sdk:src/deliveries/openapi.yaml` — machine-readable and authoritative. Worth
knowing that file exists; it answers this class of question in seconds and outranks the prose docs.

All three candidate names are real. They belong to three different requests, which is exactly why
the docs read as contradictory:

| request | field |
|---|---|
| `DeliveryReq` (create) | **`tip`** |
| `UpdateDeliveryReq` (update) | `tip_by_customer` |
| store-scoped Eats API | `courier_tip` |

**The spec then volunteered a trap:** *"The fee value in the Create Delivery response includes the
tip value."* A quote's `fee` cannot include a tip — none exists yet at quote time. So comparing the
two raw would read the whole tip as fee drift and quietly wreck the measurement §0 leans on to
decide whether absorbing restaurants need an exposure cap. `actual_fee_cents` therefore stores the
delivery fee with the tip subtracted, apples-to-apples with `quote_fee_cents`, on both the API and
webhook paths.

This is the argument for wiring the tip now rather than in §6: the trap is understood *here*.
Deferring meant someone later adds `tip`, changes no fee code, and silently corrupts the drift data
with no test failing.

### Also from the spec: `idempotency_key`

`DispatchDeliveryForOrder` retries 3 times. Without an idempotency key, a crash between Uber
creating the delivery and us persisting the assignment dispatches a **second courier** on retry —
two couriers, two bills, one order. `create()` now sends `pf-delivery-{order_id}`, a pure function
of the order; Uber honours it for 60 minutes, which covers the job's 30s/120s backoff many times
over. (`delivery_assignments.order_id` is unique, so our own table was already safe — this protects
Uber's side, which the constraint cannot reach.)

### Verification

`UberDirectProviderTest` (19) + `UberDirectAddressTest` (7) run on `Http::fake` against Uber's
verbatim documented payloads. `UberDirectLiveSandboxTest` adds a real priced quote against the
sandbox — **green as of 2026-07-14**.

## 4. Status webhooks — **built 2026-07-14**, *moved ahead of auth/capture*

Uber pushes courier status. `delivery_assignments` already carried the `[provider, external_id]`
index for the lookup — the schema anticipated this. One webhook route at
`POST admin.{domain}/webhooks/uber`, CSRF-exempt and signature-verified in the controller, driving
`DeliveryStatus` transitions and order-timeline notes.

**Why this moved:** §8 captures payment "once a courier is confirmed," but nothing in the
create-delivery response says that — Uber returns `pending` and the courier lands later, via this
webhook. As originally sequenced, auth/capture depended on a signal that didn't exist yet.

### The signing key is per-restaurant — a consequence §0 didn't chase down

Each restaurant owns its Uber account, so **each restaurant creates its own webhook in its own
dashboard and Uber mints a different signing key for each one.** There is no platform-wide secret.
This falls straight out of §0's per-restaurant account model, but it inverts how a webhook is
normally authenticated:

- `delivery_integrations.webhook_signing_key` is encrypted per-tenant, and the admin form takes it
  as a fourth, **optional** field — deliveries dispatch fine without it, you just get no status
  updates, and Uber only issues it after a separate dashboard step.
- One URL serves every tenant. The payload's `customer_id` selects *which* restaurant's key to
  verify against, so we must identify the sender before we can authenticate it.
- That is safe because the claimed identity selects the key but grants nothing: a forged
  `customer_id` merely means the signature is checked against a different key and fails. The
  signature stays the only thing that authorizes a write. Resolution is **strictly** by
  `customer_id` — an earlier `delivery_id` fallback was removed because it quietly made the claimed
  customer irrelevant whenever a delivery id happened to match.
- **Onboarding cost:** every restaurant does a webhook setup step and pastes a key. Inherent to the
  account model; the settings screen shows the URL and warns when the key is missing.

### Out-of-order retries

Uber retries at 10s/40s/100s/220s, so a stale `pending` can land *after* `delivered`.
`delivery_assignments.last_event_at` records Uber's own event clock and anything not newer is
dropped, rather than letting a retry walk the status backwards — which would tell a customer their
delivered order is pending, and (once §8 lands) confuse the capture decision.

Two header names are accepted (`x-uber-signature` and `x-postmates-signature`) because Uber sends
one or the other depending on event type; betting on one would silently drop half the traffic.

### Verification

`UberDirectWebhookTest` (15) covers signature rejection, body tampering, cross-restaurant key
confusion, unknown customers, out-of-order retries, and CSRF exemption. Signature verification is
security code, so it gets adversarial coverage rather than a happy path.

## 5. Address capture — **built 2026-07-14**

Places autocomplete on the checkout address field (backend proxy + our own dropdown, per §0) plus a
separate unit/apt field. The formatted result lands in the existing `orders.delivery_address` JSON
snapshot, which becomes the **single source of truth** for both quote and create (see the
byte-identical rule in §3). Assembled once, not twice.

Note: once a courier accepts, **addresses cannot be changed** — cancel and recreate. That constrains
what customers may edit post-checkout.

**Verified end-to-end before building any of it:** Places (New) autocomplete → `placeId` → Place
Details → `addressComponents` → snapshot → `UberDirectAddress` → a real Uber quote ($7.99). The risk
worth checking was whether Places returns *structured* components or only a formatted string; it
returns components, including `administrative_area_level_1.shortText` = `UT`, which is the form
Uber's structured address wants. No address parsing needed.

Two things that cost money if missed: Places (New) **bills per requested field** and rejects a
request with no `X-Goog-FieldMask`, and it bills autocomplete + details as **one session only when
the same `sessionToken` rides on both**. The proxy is also throttled — a public, unmetered Places
proxy is somebody else's free geocoding API.

## 6. Quote at checkout — **built 2026-07-14**, the real rework

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
available. Restaurant hours already gate this upstream (`OrderPlacement.php:53`), so
after-close orders are rejected before any of it.

Touched `OrderPlacement::prepare()`, `Checkout.vue`, and finally gives `DeliveryDispatcher::quote()` a
caller — closing the dead-code item in todo.md §8.

### What the quote is checked against

The token is opaque (a uuid, not an id) because it travels through the browser. On checkout the
quote must be **the same restaurant's**, **for the same address**, and **unexpired** — otherwise a
customer could quote a cheap address and deliver to an expensive one, or reuse another restaurant's
$1 quote. The fee itself is read from the row; it is never accepted from the client.

Address comparison deliberately **ignores delivery instructions**: editing "leave at the door"
doesn't move the courier, so it must not silently re-price the order. Changing the unit *does*.

`DeliveryDispatcher` now replays the checkout quote when it is still valid, and re-quotes only when
it has expired — the customer may have lingered on Stripe's hosted page past the 15-minute life.
Replaying keeps the price Uber honours identical to the price the customer saw.

**Self-delivery has no quote**, because there is no provider to ask; it keeps the restaurant's own
advertised fee. Third-party **requires** one. That is the whole rework in one sentence.

## 7. Pricing + kitchen ETA + the delivery settings page — **built 2026-07-14**

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

**One rule the page enforces that the schema never did:** delivery cannot be switched on without
choosing a mode. `DeliveryDispatcher` treats a null mode as third-party, so an owner who merely
flipped `delivery_enabled` would get couriers they never asked for. The choice is now required at
the only moment it is cheap to ask.

The page also shows the Uber webhook URL to paste, and warns when the signing key is missing —
deliveries still dispatch without it, you just get no status updates.

## 8. Auth/capture — **built 2026-07-15**

**There is no bare PaymentIntent.** The flow is a Stripe-hosted Checkout Session the customer is
*redirected* to. Manual capture goes on `payment_intent_data.capture_method`, and it cascaded
exactly as predicted:

- The old return handler bounced the customer unless `payment_status === 'paid'`. Under manual
  capture a *successful authorization* reads **`unpaid`** — indistinguishable from an abandoned
  checkout by that measure. It now asks the PaymentIntent instead: `requires_capture` is
  unambiguous, and it costs one API call on a path that already made one.
- Orders only existed once paid. `PaymentState` (`captured` / `authorized` / `voided`) is a
  **separate** column rather than an `OrderStatus` case, because `OrderStatus` is the *kitchen*
  lifecycle — "authorized" is not something a cook can act on, and folding it in would force every
  transition rule to reason about payments. Existing rows backfill to `captured`: until now an
  order could not exist unless the money had already moved.

### Scope: only orders that depend on a courier

Pickup and self-delivery capture immediately. Their fulfilment is a promise the restaurant can
already keep, and holding a customer's funds to wait for nothing would be pure downside. Only a
courier-network delivery authorizes — mirroring §6, where only those orders need a quote.

Target: **authorize** at checkout → create the Uber delivery → **capture** only once a courier is
confirmed (via §4's webhook). If Uber can't find a driver, void the authorization: the customer sees
a hold drop off, never a charge-and-refund.

**The POS push gates on courier confirmation too.** `OrderPlacement::queuePostPaymentFulfillment()` currently queues
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
to 30 minutes (`StripeConnectService::SESSION_TTL_MINUTES` — Stripe's own floor), record
`quote_fee_cents` vs `actual_fee_cents`, decide on a cap later with data.

### A bug this uncovered in shipped code

`OrderTransition::refundOnCancel()` **refunds** the PaymentIntent when an order is cancelled — and
Stripe rejects a refund against an uncaptured intent. So an owner cancelling a delivery order before
its courier was found would have hit a Stripe error, the refund would have silently failed (it is
best-effort and swallows exceptions), and the hold would have sat on the customer's card until the
bank dropped it a week later. It now voids instead.

A test then caught the sharper half: matching on "not `Authorized`" sent **`Voided`** orders down
the refund path too, refunding a charge that never existed. Only `Captured` money can be refunded,
so that is what the condition now says.

### Verification

`DeliveryAuthCaptureTest` (10) + `AuthorizedCheckoutFlowTest` (9) + settlement cases in
`UberDirectWebhookTest` + `CancelAuthorizedOrderTest` (4). They cover the three failures that cost
real money: a capture that fails (stay `Authorized` — believing we hold money we never took is
worse), a void that fails (cancel anyway and shout; the hold expires on its own, the order would
not), and double-settlement (both entry points no-op unless still `Authorized`, because the webhook
and the deadline race by design).

Note the test harness mocks `StripeConnectService` **partially** — every money-moving method must be
named in the mock string, or an unmocked capture fires at real Stripe from the test suite.

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

1. ~~**§1** — the `delivery_enabled` fix.~~ **Done 2026-07-14.**
2. ~~**§2–3** — credentials + adapter.~~ **Done 2026-07-14**, verified against a real sandbox quote.
3. ~~**§4** — webhooks.~~ **Done 2026-07-14.** Ahead of auth/capture, which needs the
   courier-confirmed signal.
4. ~~**§5–7** — the checkout rework.~~ **Done 2026-07-14.**
5. ~~**§8** — auth/capture.~~ **Done 2026-07-15.** The Uber Direct plan is complete; §9 (DoorDash)
   is the only section left, and it is deliberately later.

### Before production

- [ ] **Provision Plateful's PRODUCTION Uber account** — it can currently mint only
      `direct.organizations`, not `eats.deliveries`. Sandbox working says nothing about it.
- [ ] **Rotate the production Uber Client Secret** — exposed in a session transcript 2026-07-14.
- [ ] **Restrict the Google Maps key** to the Places API (New) and to the production server's IP.
      It is a server-side key; an unrestricted one is a billing liability.
- [ ] Each restaurant creates its own Uber webhook and pastes the signing key (§4). Deliveries work
      without it — the §8 deadline polls Uber directly before giving up, so a missing webhook costs
      *latency* (every order waits out the deadline) rather than correctness. Writing that note is
      what surfaced the design: without the poll, a restaurant that skipped the webhook step would
      have had **every delivery silently cancel**, courier en route. The settings page warns when
      the key is missing.
- [ ] **A queue worker must be running.** Delivery dispatch and the §8 deadline are queued jobs on
      `QUEUE_CONNECTION=database`. Without a worker, authorized orders never dispatch AND never
      expire — holds sit on customer cards with nothing scheduled to release them. This is the one
      operational dependency with no in-code backstop.

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
