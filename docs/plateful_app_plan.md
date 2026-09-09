# Plateful app — consumer marketplace app + public API plan

_Drafted 2026-09-09. Status: **planning — not started.** Decisions locked at
drafting: the app is **one Plateful app, not per-restaurant apps**; every
restaurant's storefront lives inside it; diners must install Plateful first.
Per-restaurant white-label apps are a possible later product, not a
constraint on this design. Because the app is a marketplace by necessity, the
plan embraces it: build the most robust marketplace we can, on the same 4%._

## Why this exists (the strategic frame)

The web product's identity is "the restaurant's own site, the restaurant's
own customer." An app can't be that — a restaurant can't publish under
Plateful's developer account without tripping Apple's templated-app rule
(4.2.6), and we can't run a hundred store listings anyway. So the app is
Plateful-branded, multi-restaurant, DoorDash-shaped on the surface.

What makes it different is underneath, and it's the whole pitch:

- **Same 4% flat fee, same monthly cap.** An order placed in the app is a
  direct charge on the restaurant's connected Stripe account with the same
  application fee as a storefront order. DoorDash charges 15–30% for exactly
  this surface. Say it out loud and it becomes a pricing promise of the same
  class as the public $399 cap and the marketplace plan's "no extra
  commission for marketplace orders" — decide it deliberately (⚑ below).
- **The customer still belongs to the restaurant.** App orders write the
  same `restaurant_customer` rows, the same marketing consent, the same
  loyalty ledger, and show up on the same Customers page. DoorDash's contract
  says merchants don't own the customer data; ours says they do, in the app
  too.
- **The order hits the same kitchen.** `OrderPlacement` → POS push (Square /
  Clover) → delivery dispatch (Uber Direct) is the same pipeline. The app
  adds zero operational surface for the restaurant.

**Reconciling with the marketplace plan's rule.** `marketplace_menu_browsing_plan.md`
says "Ordering on plateful.fyi itself — never, by design." That rule protects
the *principle* (the restaurant's relationship, the restaurant's margin), not
the *domain*. The app keeps the principle and moves the transaction UI. Amend
the marketplace doc's line to "on the web" when this plan is picked up; the
web storefront stays the place a diner orders without ever touching Plateful.

## What already exists (verified 2026-09-09 — the API is mostly reuse)

The codebase is unusually well-positioned for a second client:

- **Business logic is already out of the controllers.** `OrderPlacement`
  (prepare → materialize), `OrderTransition`, `CartManager`, `LoyaltyService`,
  `MarketingConsentService`, `Refunds`, `Delivery/*`, `Pos/*`, `Stripe/*` are
  all plain services. API controllers call the same classes.
- **Response shapes exist.** The 25 DTOs in `app/Data` (`RestaurantData`,
  `MenuCategoryData`, `CartData`, `OrderData`, `AddressData`,
  `AccountSummaryData`, …) are what Inertia already serialises to the Vue
  pages. They *are* the API contract; the app receives the same JSON. This is
  the existing convention, so use them directly rather than adding a parallel
  set of Eloquent API Resources.
- **Tenant context is a container singleton** (`App\Tenancy\CurrentTenant`)
  set by `ResolveTenant` from the host. A second middleware that sets it from
  a route-bound restaurant is a few lines, and every service downstream is
  unchanged. (`project_tenant_resolution` memory: designed for this.)
- **Policies + form requests** already gate the sensitive paths
  (`OrderPolicy`, `MenuItemPolicy`, `CheckoutRequest`). Throttles already sit
  on the expensive endpoints (checkout, address lookup, delivery quote).
- **`Restaurant::scopePublic()`** already defines marketplace eligibility;
  `publicUrl()` resolves custom domains; hours/`isOpenAt()`/`formatNextOpenAt()`
  exist for "open now."
- **Google Places service** exists (`Services/Places`) — geocoding
  restaurants for "near me" is a call we already know how to make.
- **Order confirmation already polls** for status/dispatch on the web; the
  same state machine feeds push in the app.

## The three pieces of real work (everything else is routine)

1. **Auth.** Only the `web` session guard exists. Needs Sanctum bearer
   tokens, a token-issuing login, and social sign-in by *ID token* (the app
   gets a token from the native SDK; no redirect handoff). **Apple's 4.8
   rule: offering Google sign-in requires offering Sign in with Apple** —
   that's a new server-side verifier, not a Socialite redirect. Admin 2FA
   only matters for the later operator app.
2. **Cart identity.** `CartManager` reads and queues a browser cookie. It
   needs to accept a cart token from a header (`X-Cart-Token`) or bind to the
   bearer user. One class, small refactor, existing tests cover the rest.
3. **Payments.** Web checkout redirects to a Stripe-hosted session and the
   webhook materialises the order. A native app wants **PaymentSheet** with a
   PaymentIntent client secret on the connected account (direct charge,
   `application_fee_amount`, `capture_method: manual` for courier delivery —
   the same manual-capture promise as the web). `StripeConnectService`
   already captures/voids/refunds PaymentIntents and `materialize()` already
   accepts a payment-intent id, so this is a second *creation* path plus a
   webhook branch, not a rewrite. It is still the piece to be most careful
   with.

**One genuinely new piece of infrastructure: push notifications.** Nothing
sends to APNs/FCM today. It is also the app's single biggest advantage over
the web storefront ("your order is ready" on the lock screen), so it's in the
v1 scope, not the backlog.

## Ideas worth building in (Claude's suggestions — ⚑ where they need a call)

The app's structural advantage over N separate storefronts is **one
account across every restaurant.** Lean into that:

- **One-tap reorder.** From order history, rebuild the cart from a past
  order's items (skip unavailable ones, say so). Cheapest retention feature
  in the plan; do it in v1.
- **One saved card everywhere.** With direct charges, a card saved for
  restaurant A is on A's connected account, not B's. Two paths: (a) v1 —
  turn on **Stripe Link** in PaymentSheet and let Stripe carry the saved card
  across merchants for free; (b) later — a platform-level Stripe Customer
  whose PaymentMethod is cloned to the connected account at charge time
  (Stripe supports this for Connect). Start with (a). Apple Pay / Google Pay
  come with PaymentSheet under the platform's merchant identifier.
- **One address book.** Already exists on `User`; the app just gets it on
  every restaurant's checkout instead of only where the user happened to save
  it.
- **Rewards wallet.** §10 says loyalty is *restaurant-owned* — keep that.
  The app simply shows every per-restaurant balance on one "Your rewards"
  screen. This becomes a selling point to restaurants that nobody else can
  offer them: *your* loyalty program gets an app, with push, for free.
  Redemption in-app waits on §10's redemption-mechanism decision; earning and
  display don't.
- **Favorites / follow.** A "your restaurants" home feed, and — with
  explicit, separate opt-in that follows the Campaigns consent model — a
  future **push campaign** channel for restaurants ("Taco Tuesday, 2-for-1,
  tap to order"). This is Campaigns Phase 5 in all but name; don't build the
  sending side until email campaigns have proven the motion, but capture the
  follow relationship from day one.
- **Push for order status** (v1): Confirmed, Preparing, Ready, courier
  assigned / en route / delivered, Cancelled. Hook where loyalty already
  hooks: `OrderTransition` + the delivery status updates.
- **Real discovery.** Restaurants have street/city/postal but **no lat/lng
  and no cuisine field** (the marketplace plan already flagged cuisine).
  Add both: geocode on onboarding save + a backfill command via the existing
  Places service; have the menu-extraction AI emit cuisine tags (it already
  reads the whole menu) with an owner-editable field. Then: "near me,"
  "open now," cuisine chips, pickup/delivery toggle.
- **Universal links + QR.** `plateful.fyi/restaurants/{subdomain}`
  (marketplace plan Phase 1) opens the restaurant *in the app* when
  installed, the web menu otherwise. Print it as a QR for tables, bags, and
  windows: "scan to order, skip the line." One asset gives restaurants a
  physical-world funnel into their own ordering — and ties the web
  marketplace and the app into one system instead of two.
- **Restaurant listing controls (⚑).** Default every `scopePublic()`
  restaurant into the app (it's free demand) with a per-restaurant
  `marketplace_listed` opt-out and an admin toggle. Recommended: default on.
- **Operator app second, same API.** Kitchen display, order alerts by push,
  accept/ready/complete, pause ordering (§9). Mostly existing read endpoints
  + `OrdersController@transition`. It's where push pays off *most* for the
  restaurant, and it needs no discovery, no payments, no store-review
  ambiguity — a strong candidate for the second release rather than the
  first, so the consumer marketplace gets the launch attention.

## Architecture

### API surface

- Mount at **`https://plateful.fyi/api/v1/…`** (primary host; `ResolveTenant`
  already passes the primary domain through, and Laravel Cloud needs no new
  DNS/cert). `api.plateful.fyi` can alias it later. Register `routes/api.php`
  under the `api` middleware group (stateless: no CSRF, no session).
- Tenant-scoped routes: `/api/v1/restaurants/{restaurant:subdomain}/…` with
  a `ResolveTenantFromRoute` middleware that calls `CurrentTenant::set()` on
  the bound model (public-scope only) so `CartManager`, `OrderPlacement`,
  delivery quotes, etc. run unchanged. Custom-domain resolution is irrelevant
  here — the app addresses restaurants by subdomain (their stable slug).
- **Versioning discipline is the real new cost.** Today the Vue pages ship
  with the payloads; the app doesn't. From the day v1 ships, `app/Data` DTO
  changes must be additive (add fields, never rename/remove) or go behind
  `/v2`. Add a Pest architecture/snapshot test that pins the v1 DTO shapes.
- Responses: DTOs serialised as JSON, wrapped `{ data: … }`; pagination for
  lists; errors as `{ message, errors }` (Laravel's default is fine and
  Inertia's form requests already produce it).
- Rate limiting: reuse the storefront throttles; key by token where
  authenticated, by IP otherwise.

### Auth

- **Sanctum** (new dependency — needs approval). One personal access token
  per device (`name` = device), abilities `customer` now, `operator` later.
- `POST /auth/login` (email + password via `Auth::validate`; honour
  Fortify's throttling and, if the user has 2FA enrolled, a `two_factor`
  challenge step), `POST /auth/register`, `POST /auth/forgot-password`
  (reuse the password broker), `POST /auth/google` (verify Google ID token
  server-side; Socialite's `userFromToken` or a plain JWT verify against
  Google's JWKS), `POST /auth/apple` (verify the identity token against
  Apple's JWKS; capture name/email on first sign-in — Apple only sends them
  once), `DELETE /auth/logout` (revoke current token), `GET /me`.
- Social sign-in must land on the same `google_id`/email matching rules as
  `GoogleController` so a web customer and an app customer are one user.
  Add an `apple_id` column (nullable, partial unique like `google_id`).
- Guest browsing is unauthenticated. Checkout requires a signed-in user
  **or** guest details exactly as the web does (`CheckoutRequest` already
  handles both) — decide whether the app allows guest checkout at all (⚑;
  recommended: yes, parity with web, it's the lowest-friction first order,
  and the account prompt comes after with the order to attach).

### Cart

- `CartManager`: resolve the cart token from `X-Cart-Token` first, cookie
  second; expose the token on cart responses so the app persists it; on
  login, merge the header cart into the user cart (the web path already
  reattaches by user). No schema change.

### Checkout & payments

- `POST /restaurants/{r}/checkout/intents` → runs `OrderPlacement::prepare()`
  (same validation, same totals, same fee math including §10's
  post-redemption rule when that ships), stores a `PendingCheckout` exactly
  as the web does, then `StripeConnectService::createPaymentIntent()` on the
  connected account with `application_fee_amount`, `capture_method: manual`
  when courier delivery, idempotency key `pending_checkout_{id}`, metadata
  `pending_checkout_id`. Returns `client_secret` + the connected account id
  for PaymentSheet.
- `POST /checkout/{pending}/confirm` after PaymentSheet succeeds → retrieve
  the PI on the connected account, materialise (mirrors `paymentReturn()`).
- Webhook: `payment_intent.succeeded` / `payment_intent.amount_capturable_updated`
  on connected accounts → materialise by `pending_checkout_id` metadata.
  Idempotency: mirror the existing unique `stripe_checkout_session_id`
  short-circuit on `stripe_payment_intent_id` (column exists; verify the
  unique index at build time).
- Delivery quotes: `POST /restaurants/{r}/delivery-quote` wraps the existing
  `DeliveryQuoteController` logic; address suggest/resolve wrap the Places
  proxy with the same throttles.
- Refunds, captures, voids, courier fallback: **unchanged** — they operate on
  the PaymentIntent, which is why this path is cheaper than it looks.
- **Stripe Terminal-style saved cards are NOT v1** — Link is.

### Orders, account, push

- `GET /orders`, `GET /orders/{number}` (poll-friendly; includes delivery
  tracking URL as the web does), `POST /orders/{number}/reorder` → cart.
- Addresses CRUD, profile, marketing consent, loyalty balances (aggregate
  across `restaurant_customer` / `loyalty_points` by user), favorites
  (`user_restaurant_favorites`: user_id, restaurant_id, timestamps).
- **Push**: `device_tokens` (user_id, platform, token, provider, last_seen_at)
  + `POST /me/devices`. Deliver via **Expo Push** if the app is Expo (one
  provider, no APNs/FCM plumbing) or FCM+APNs via a notification channel
  otherwise. A `Notifications/OrderStatusChanged` notification fired from
  `OrderTransition` and the delivery-status updater. Quiet failures
  (dead tokens → prune).

### Discovery

- Migration: `restaurants.latitude`, `longitude` (decimal), `cuisine_tags`
  (json), `marketplace_listed` (bool, default true). Geocode in
  `OnboardingController@updateBasics` / settings save and in a one-shot
  `restaurants:geocode` command for existing rows.
- `GET /restaurants?lat&lng&radius_km&open_now&cuisine&q&fulfilment=pickup|delivery`
  — Haversine in SQL (Postgres, fine at this scale; PostGIS is not needed
  until it is), `scopePublic()` + `marketplace_listed`, ordered by distance,
  paginated. `GET /restaurants/{r}` (hero, hours, open-now, delivery
  settings), `GET /restaurants/{r}/menu` (the `MenuController` category
  query, extracted to a shared query object — the marketplace plan wants the
  same extraction).

### The app itself (⚑ stack)

- **Recommended: Expo (React Native).** PaymentSheet, push, maps, universal
  links, Sign in with Apple, and EAS store builds are all first-class, and
  the same codebase could later produce per-restaurant white-label builds
  from config if that product ever exists. Trade-off: it's TypeScript but
  not Vue.
- **Alternative: Capacitor + Vue (Ionic).** Reuses the Vue/Tailwind skills,
  but Apple rejects apps that are "just a website" (4.2), so it must be a
  real app shell against the API, **not** a webview of the storefront. Stripe
  and push work via community plugins; more glue, fewer guarantees.
- Not recommended: a bare webview wrapper of the existing storefront. It
  ships fastest and would probably fail review for the *marketplace* app
  (and gives none of the one-account advantages).
- Store facts: food ordering is physical goods → Stripe is allowed, no IAP
  (3.1.3(e)/3.1.5). Enrol in the App Store Small Business Program anyway
  (it's free; irrelevant to commission since we don't use IAP, but it's
  the right account posture). Google Play is the same story.
- v1 screens: Discover (list + map, filters) · Restaurant (hero, hours, menu,
  item drawer with option groups) · Cart · Checkout (pickup/delivery,
  address, tip presets, PaymentSheet) · Order status (live, push-backed) ·
  Orders (history, reorder) · Account (profile, addresses, rewards wallet,
  favorites, notification prefs, delete account — Apple requires in-app
  account deletion; the web `profile.destroy` hard-delete already exists).

## Phases

### Phase 0 — decisions + API foundation (~2 sessions)
Sanctum; `routes/api.php`; `ResolveTenantFromRoute`; auth endpoints incl.
Google + Apple ID-token verifiers and `apple_id`; `GET /me`; DTO contract
test; Pest coverage for every endpoint (`Sanctum::actingAs`). Nothing
visible to anyone yet.

### Phase 1 — marketplace read API + discovery data (~1–2 sessions)
Geo + cuisine + `marketplace_listed` migration and backfill; restaurants
list/detail/menu endpoints; shared menu query object (also unblocks the
marketplace web plan's Phase 1). **The app track can start here** against
real data.

### Phase 2 — ordering (~3–4 sessions; payments are most of it)
Header-token carts; checkout intents + confirm + connected-account webhook
branch; delivery quote + address endpoints; order show. Test against the
existing `StripeCheckoutTest` style fixtures; add the manual-capture and
idempotency cases beside the web ones.

### Phase 3 — account, retention, push (~2–3 sessions)
Order history, reorder, addresses, rewards wallet, favorites, device tokens,
`OrderStatusChanged` notifications wired into `OrderTransition` + delivery
updates. Notification preferences on the user.

### Phase 4 — the app (~4–6 sessions, parallel from Phase 1)
Expo project, screens above, EAS builds, TestFlight/internal testing with
the testaurant, store listings, privacy manifest/nutrition labels, review.

### Phase 5 — growth (after launch, on evidence)
Universal links + QR assets for restaurants; push campaigns as a Campaigns
extension (separate opt-in); cuisine/city browse; **operator app** (kitchen +
alerts) on the same API; per-restaurant white-label builds only if a client
will publish under their own Apple account.

## Testing

Feature tests per endpoint (auth matrix, tenant scoping — a token for
restaurant A's cart can never touch B's; guest vs authed carts; checkout
intent totals equal web totals for the same cart; manual capture only for
courier delivery; webhook idempotency; push fires once per transition; DTO
snapshot). No new browser tests — the app is the client.

## Sequencing triggers

1. After the remaining §0 launch blockers and the §11 hardening list — this
   is growth surface, not launch surface, same as the web marketplace plan.
2. Phases 0–1 are worth doing early regardless: they're cheap, they unblock
   the web marketplace plan's shared menu query, and geocoding/cuisine data
   compounds.
3. Phase 2 before the first restaurant asks "are you on an app?" — which
   will be the first sales conversation after "are you on DoorDash?"
4. §10 loyalty redemption should be decided (not necessarily built) before
   Phase 3 so the wallet screen doesn't promise the wrong mechanism.

## Decision points recap (⚑ = Taylor)

- ⚑ **Say "same 4% in the app" publicly?** Recommended yes — it's the whole
  differentiator — but it's a permanent promise once said.
- ⚑ **Stack: Expo vs Capacitor+Vue.** Recommended Expo.
- ⚑ **Guest checkout in the app.** Recommended yes (web parity).
- ⚑ **Restaurant listing default.** Recommended listed-by-default with opt-out.
- ⚑ **Sanctum** as a new dependency (approval per project rules).
- ⚑ **Push provider:** Expo Push (if Expo) vs FCM/APNs direct.
- ⚑ **Consumer app first, operator app second** (this plan's assumption).
- Open, non-blocking: `api.plateful.fyi` alias; PostGIS if/when scale
  demands; platform-level saved cards beyond Link.
