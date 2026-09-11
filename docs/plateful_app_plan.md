# Plateful app — public API plan (server side)

_Drafted 2026-09-09, trimmed the same day to **API and data work only**. The
app itself (stack, screens, builds, store submission) is planned in the
separate app repo: `~/Projects/plateful-app/docs/app_plan.md`. Status:
**Phase 0 built 2026-09-11** (Sanctum, `/api/v1` auth incl. Google + Apple
ID tokens, two-factor challenge, `GET/DELETE /me`, `ResolveTenantFromRoute`,
contract snapshot); Phase 1 next. Locked at drafting: **one Plateful app, not
per-restaurant apps**; every restaurant's storefront lives inside it. The app
is therefore a marketplace, and the API embraces that._

## Why this exists (the strategic frame, briefly)

The app is Plateful-branded and multi-restaurant because a restaurant can't
publish under our developer account (Apple 4.2.6) and we can't run a hundred
listings. DoorDash-shaped on the surface; different underneath, and the API
is where the difference is enforced:

- **Same 4% flat fee, same cap.** App orders are direct charges on the
  restaurant's connected Stripe account with the same application fee as a
  storefront order. Saying this publicly is a permanent pricing promise of
  the same class as the $399 cap (⚑).
- **The customer still belongs to the restaurant.** App orders write the same
  `restaurant_customer`, consent, and loyalty rows and appear on the same
  Customers page.
- **The order hits the same kitchen.** `OrderPlacement` → POS push → delivery
  dispatch, unchanged.

**Reconciling with the marketplace plan.** `marketplace_menu_browsing_plan.md`
says "Ordering on plateful.fyi itself — never, by design." That protects the
principle (restaurant's relationship, restaurant's margin), not the domain.
Amend that line to "on the web" when this is picked up.

## What already exists (verified 2026-09-09 — the API is mostly reuse)

- **Business logic is already out of the controllers.** `OrderPlacement`
  (prepare → materialize), `OrderTransition`, `CartManager`, `LoyaltyService`,
  `MarketingConsentService`, `Refunds`, `Delivery/*`, `Pos/*`, `Stripe/*` are
  plain services. API controllers call the same classes.
- **Response shapes exist and already generate TypeScript.** The 25 DTOs in
  `app/Data` are Spatie data classes tagged `#[TypeScript]`;
  `laravel-typescript-transformer` writes `resources/js/types/generated.d.ts`.
  They *are* the API contract, and that generated file is what the app repo
  consumes (see "Contract" below). Use them directly; no parallel set of
  Eloquent API Resources.
- **Tenant context is a container singleton** (`App\Tenancy\CurrentTenant`)
  set by `ResolveTenant` from the host. A second middleware that sets it from
  a route-bound restaurant is a few lines; every service downstream is
  unchanged.
- **Policies, form requests, throttles** already gate the sensitive and
  expensive paths (`OrderPolicy`, `CheckoutRequest`, checkout/address/quote
  throttles).
- **`Restaurant::scopePublic()`**, `publicUrl()`, hours + `isOpenAt()` exist.
- **Google Places service** exists (`Services/Places`) for geocoding.
- **Order confirmation already polls** on the web; the same state machine
  feeds push.

## The three pieces of real work (everything else is routine)

1. **Auth.** Only the `web` session guard exists. Needs Sanctum bearer
   tokens, a token-issuing login, and social sign-in by *ID token*. Apple's
   4.8 rule means Sign in with Apple is required once Google is offered, so
   an Apple identity-token verifier is Phase 0 work.
2. **Cart identity.** `CartManager` reads and queues a browser cookie. It
   needs to accept `X-Cart-Token` or bind to the bearer user. One class.
3. **Payments.** Web checkout is a Stripe-hosted session + webhook. The app
   needs a **PaymentIntent** on the connected account (direct charge,
   `application_fee_amount`, `capture_method: manual` for courier delivery).
   `StripeConnectService` already captures/voids/refunds PaymentIntents and
   `materialize()` already accepts a payment-intent id, so this is a second
   creation path plus a webhook branch. Still the piece to be most careful
   with.

**One new piece of infrastructure: push.** Nothing sends to APNs/FCM today.
Server side this is a device-token table and a notification fired from the
transitions that already exist.

## Data and endpoints the app's feature ideas need

(The product reasoning for each lives in the app plan; this is the server
work each one implies.)

- **One-tap reorder** — `POST /orders/{number}/reorder` rebuilds a cart from
  a past order, skipping unavailable items and reporting which.
- **One saved card everywhere** — v1 is Stripe **Link** in PaymentSheet
  (zero server work). Later: platform-level Stripe Customer + PaymentMethod
  cloned to the connected account at charge time.
- **Rewards wallet** — an aggregate endpoint over `loyalty_points` /
  `restaurant_customer` by user. §10's per-restaurant ownership is
  unchanged; in-app redemption waits on §10's mechanism decision.
- **Favorites / follow** — `user_restaurant_favorites` (user_id,
  restaurant_id). Capture from day one; the push-campaign channel it enables
  is a later Campaigns extension with its own opt-in.
- **Push for order status** — `device_tokens` + `OrderStatusChanged`
  notification from `OrderTransition` and delivery-status updates.
- **Discovery** — `restaurants.latitude/longitude` (geocode on onboarding
  save + backfill command), `cuisine_tags` (menu-extraction AI emits them;
  owner-editable), `marketplace_listed` (⚑ default on, per-restaurant
  opt-out + admin toggle).
- **Universal links** — serve `/.well-known/apple-app-site-association` and
  `assetlinks.json` from the primary host so
  `plateful.fyi/restaurants/{subdomain}` opens in the app.
- **Operator app (later)** — mostly existing admin reads +
  `OrdersController@transition`, behind an `operator` token ability.

## Architecture

### API surface

- Mount at **`https://plateful.fyi/api/v1/…`** (primary host passes through
  `ResolveTenant`; no new DNS/cert). `api.plateful.fyi` can alias later.
  `routes/api.php` under the `api` group (stateless: no CSRF, no session).
- Tenant-scoped routes: `/api/v1/restaurants/{restaurant:subdomain}/…` with
  `ResolveTenantFromRoute` calling `CurrentTenant::set()` on the bound,
  public-scoped model so `CartManager`, `OrderPlacement`, quotes, etc. run
  unchanged. Custom domains are irrelevant here; the app addresses
  restaurants by subdomain.
- Responses: DTOs as JSON wrapped `{ data: … }`; paginated lists; Laravel's
  default `{ message, errors }` validation shape.
- Rate limiting: reuse the storefront throttles; key by token when
  authenticated, IP otherwise.

### Contract (the new discipline)

Today the Vue pages ship with the payloads; the app doesn't. From v1 ship:

- `app/Data` DTO changes are **additive only** (add fields; never rename or
  remove) or go behind `/v2`.
- A Pest snapshot test pins the v1 DTO shapes.
- `resources/js/types/generated.d.ts` is the artifact the app repo copies
  (or a tiny shared package publishes). Regenerate on every DTO change.

### Auth

- **Sanctum** (⚑ new dependency). One token per device (`name` = device);
  abilities `customer` now, `operator` later.
- `POST /auth/login` (`Auth::validate`, Fortify throttling; `two_factor`
  challenge step if enrolled), `POST /auth/register`,
  `POST /auth/forgot-password` (password broker), `POST /auth/google`
  (verify ID token against Google JWKS), `POST /auth/apple` (verify identity
  token against Apple JWKS; capture name/email on first sign-in — Apple
  sends them once), `DELETE /auth/logout`, `GET /me`,
  `DELETE /me` (Apple requires in-app account deletion; reuse the web
  hard-delete path).
- Match `google_id`/email exactly as `GoogleController` does so web and app
  customers are one user. Add `apple_id` (nullable, partial unique like
  `google_id`).
- Guest browsing unauthenticated; checkout accepts a signed-in user **or**
  guest details as `CheckoutRequest` already does (⚑ guest checkout in-app;
  recommended yes, web parity).

### Cart

- `CartManager`: token from `X-Cart-Token` first, cookie second; expose the
  token on cart responses; merge header cart into user cart on login (web
  already reattaches by user). No schema change.

### Checkout & payments

- `POST /restaurants/{r}/checkout/intents` → `OrderPlacement::prepare()`
  (same validation, totals, fee math, incl. §10's post-redemption rule when
  it ships) → `PendingCheckout` row as today →
  `StripeConnectService::createPaymentIntent()` on the connected account
  (`application_fee_amount`, manual capture for courier delivery,
  idempotency key `pending_checkout_{id}`, metadata `pending_checkout_id`).
  Returns `client_secret` + connected account id.
- `POST /checkout/{pending}/confirm` → retrieve the PI, materialise (mirrors
  `paymentReturn()`).
- Webhook: `payment_intent.succeeded` /
  `payment_intent.amount_capturable_updated` on connected accounts →
  materialise by `pending_checkout_id`. Idempotency: mirror the
  `stripe_checkout_session_id` short-circuit on `stripe_payment_intent_id`
  (verify the unique index at build time).
- Delivery quote + address suggest/resolve wrap the existing controllers
  with the same throttles.
- Refunds, captures, voids, courier fallback: **unchanged**.

### Orders, account, push

- `GET /orders`, `GET /orders/{number}` (poll-friendly; includes tracking
  URL), `POST /orders/{number}/reorder`.
- Addresses CRUD, profile, marketing consent, loyalty wallet, favorites.
- `device_tokens` (user_id, platform, token, provider, last_seen_at) +
  `POST /me/devices`. Provider: **Expo Push** (⚑; matches the app stack, no
  APNs/FCM plumbing). Prune dead tokens on failure.

### Discovery

- Migration: `latitude`, `longitude` (decimal), `cuisine_tags` (json),
  `marketplace_listed` (bool, default true). Geocode in the onboarding/
  settings save + `restaurants:geocode` backfill.
- `GET /restaurants?lat&lng&radius_km&open_now&cuisine&q&fulfilment=` —
  Haversine in SQL (PostGIS only if scale demands), `scopePublic()` +
  `marketplace_listed`, distance-ordered, paginated. `GET /restaurants/{r}`,
  `GET /restaurants/{r}/menu` (the `MenuController` category query extracted
  to a shared query object — the web marketplace plan wants the same).

## Phases (server side; the app repo's phases align to these)

### Phase 0 — API foundation — DONE 2026-09-11 (one session)
Sanctum; `routes/api.php`; `ResolveTenantFromRoute`; auth endpoints incl.
Google + Apple verifiers and `apple_id`; `GET /me`, `DELETE /me`; DTO
snapshot test; Pest coverage for every endpoint.

As built (decisions taken at build time, all recommended-path):
- Registration is **tenant-less** (`POST /auth/register` makes a plain
  Plateful account; the `restaurant_customer` row arrives with the first
  order/favorite). Login accepts any live account — no admin/tenant split.
- Two-factor: login answers **202** with a 5-minute challenge token
  (ability `two-factor-challenge`); `POST /auth/two-factor` takes a TOTP or
  recovery code and swaps it for the device token. Skipped in `local`, same
  as the web pipeline.
- ID tokens are verified against the providers' JWKS (`firebase/php-jwt`,
  now a direct dependency; keys cached 1h). Google accepts
  `GOOGLE_APP_CLIENT_IDS` (iOS/Android) plus the web client id; Apple
  accepts `APPLE_CLIENT_IDS`. Unconfigured provider → 503. Apple's name is
  forwarded by the app on first sign-in (`name` field). No nonce check yet.
- The matching rules moved to `SocialAccountResolver`, shared with the web
  Google callback, so web and app customers are one account.
- `DELETE /me` = the web hard delete; the bearer token is the proof of
  intent (no password re-entry — social accounts have none). Last super
  admin → 409.
- One token per device (`device_name`); re-login replaces it. Sanctum runs
  pure-bearer (`sanctum.guard = []`), so a browser session never reaches
  `/api/*`. Limiters: `api` 120/min, `api-auth` 10/min/IP, `login` (Fortify's).
- Contract test: `tests/Feature/Api/V1/ContractTest.php` snapshots the
  constructor shape of every v1 DTO; add new DTOs to `API_V1_CONTRACT`.

### Phase 1 — read API + discovery data (~1–2 sessions)
Geo/cuisine/`marketplace_listed` migration + backfill; restaurants
list/detail/menu; shared menu query object; regenerate and hand off
`generated.d.ts`. **The app track starts here.**

### Phase 2 — ordering (~3–4 sessions; payments are most of it)
Header-token carts; checkout intents + confirm + connected-account webhook
branch; quote + address endpoints; order show. Add manual-capture and
idempotency cases beside the existing `StripeCheckoutTest` ones.

### Phase 3 — retention + push (~2–3 sessions)
Order history, reorder, addresses, wallet, favorites, device tokens,
`OrderStatusChanged` notifications, notification preferences.

### Later (on evidence)
Universal-link association files; push campaigns as a Campaigns extension;
`operator` ability + kitchen endpoints for the operator app; platform-level
saved cards beyond Link.

## Testing

Feature tests per endpoint: auth matrix; tenant scoping (restaurant A's
cart token can never touch B); guest vs authed carts; checkout-intent totals
equal web totals for the same cart; manual capture only for courier
delivery; webhook idempotency; push fires once per transition; DTO snapshot.
No browser tests — the app is the client.

## Sequencing triggers

1. After the remaining §0 launch blockers and the §11 hardening list —
   growth surface, not launch surface.
2. Phases 0–1 are cheap and worth doing early regardless: they unblock the
   web marketplace plan's shared menu query, and geo/cuisine data compounds.
3. Phase 2 before the first restaurant asks "are you on an app?"
4. Decide §10's redemption mechanism before Phase 3 so the wallet endpoint
   doesn't promise the wrong thing.

## Decision points recap (⚑ = Taylor)

- ⚑ "Same 4% in the app" said publicly (permanent promise). Recommended yes.
- ⚑ Sanctum as a new dependency.
- ⚑ Guest checkout in the app. Recommended yes.
- ⚑ Restaurant listing default. Recommended listed-by-default with opt-out.
- ⚑ Push provider: Expo Push (recommended, matches the app stack).
- Open, non-blocking: `api.plateful.fyi` alias; PostGIS; saved cards beyond
  Link.
