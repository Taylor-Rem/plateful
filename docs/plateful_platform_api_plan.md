# Plateful platform API — plan (operator API, machine auth, outbound webhooks, docs)

_Drafted 2026-09-14 as a hand-off for a fresh session. Status: **planning — not
started.** Builds directly on the customer API shipped 2026-09-11
(`docs/plateful_app_plan.md`, Phases 0–3, all live on `plateful.fyi/api/v1`).
Nothing here is a decision Taylor has made yet; every ⚑ is his call, and the
first session should ask them before writing code (see "Decision points")._

## What "full-fledged" means here

The customer API (40 routes) covers everything a diner does. A full API adds
the other two audiences:

1. **Operators** — the restaurant's own staff, from an operator app or a
   third-party tool: orders, kitchen, menu, hours, availability, settings.
2. **Machines** — integrations that act for a restaurant without a person
   signed in (a POS bridge, a partner marketplace, a printer service):
   API keys with scopes, outbound webhooks, idempotency, docs.

The super-admin surface stays web-only until something external needs it.

## Why it is cheap (read this before estimating)

- **All business logic is already in services**, not controllers: 62 classes
  under `app/Services` and `app/Support` (`OrderTransition`, `OrderPlacement`,
  `MenuBuilder`, `IngredientEditor`, `IngredientGroupCompiler`, `CartManager`,
  `Refunds/*`, `Delivery/*`, `Pos/*`, `Stripe/*`, `MarketingConsentService`,
  `LoyaltyService`, `AddressBook`, `OrderNotifier`). The tenant-admin
  controllers (32 routes, `app/Http/Controllers/Admin/TenantAdmin`) are thin
  Inertia wrappers with 55 form requests that can be reused verbatim.
- **The API conventions exist and are tested**: `routes/api.php` under
  `Route::domain(primary)->prefix('api/v1')`; `tenant.route`
  (`ResolveTenantFromRoute`, runs *before* `SubstituteBindings` so
  tenant-scoped bindings are safe); `auth.optional`; Sanctum abilities
  (`customer`, `two-factor-challenge`); `{data, meta}` envelopes with
  `PaginationMetaData`; DTOs in `app/Data` tagged `#[TypeScript]`; the
  contract snapshot in `tests/Feature/Api/V1/ContractTest.php`
  (`API_V1_CONTRACT` list); JSON errors via `shouldRenderJsonWhen('api/*')`;
  named limiters `api`, `api-auth`, `api-checkout`, `api-two-factor`.
- **The hooks for outbound events already fire** at the right moments:
  `OrderTransition::apply()` and `DeliveryAssignmentObserver` call
  `OrderNotifier` (push). A webhook dispatcher is a second listener on the
  same two seams, plus `OrderPlacement::materialize()` for "order created".
- **Test helpers exist**: `tests/Feature/Api/V1/ApiHelpers.php`
  (`apiTokenFor`, `forgetApiGuards` — flushes persisted default headers
  too), `ApiCheckoutHelpers.php` (`fakePaymentIntents`, `addPepViaApi`,
  `postStripeEvent`), `tests/Feature/Storefront/CartTestHelpers.php`
  (`cartFixture`). The fixture pizza is **$14** (defaults included).

## Decision points (⚑ = Taylor; ask before coding)

- ⚑ **Who consumes the operator API first?** (a) our own operator app
  (same Expo stack, same Sanctum tokens with an `operator` ability), or
  (b) third parties from day one (needs API keys + docs before anything
  else). Recommended: (a) first; keys and docs are Phase 2.
- ⚑ **Machine credential model.** Recommended: **API keys as Sanctum
  tokens** owned by a *restaurant*, not a user — a new `api_keys` table
  (restaurant_id, name, hashed key, scopes, last_used_at, revoked_at) with
  a small guard, or plain Sanctum tokens attached to a per-restaurant
  service `User`. Passport/OAuth only if outside developers must self-serve;
  it is a dependency change and a bigger surface. Recommended: no Passport.
- ⚑ **Webhook delivery guarantees.** At-least-once with retries (backoff
  1m/5m/30m/2h/12h, 5 attempts) and HMAC-SHA256 signatures, mirroring what
  we already verify inbound from Stripe/Uber/DoorDash. Recommended yes.
- ⚑ **Docs generator.** `dedoc/scramble` generates OpenAPI from the
  controllers/requests/DTOs with no annotations; it is a **new dependency**
  (needs approval). Alternative: hand-maintained OpenAPI YAML (drifts).
  Recommended: Scramble, published at `/docs/api` on the primary host.
- ⚑ **Backward-compat promise.** Once a third party builds on `/v1`, the
  additive-only DTO rule becomes a contract with outsiders. Say it
  deliberately (same class as the 4% and the $399 cap promises).
- Non-blocking: rate limits per key (start 300/min), IP allow-lists per key,
  a `sandbox` mode (test restaurants + test Stripe) for integrators.

## Phase 1 — Operator API (~2–3 sessions)

Ability `operator` on Sanctum tokens. Login already exists
(`POST /auth/login`); add `abilities` derived from the user: `customer`
always, plus `operator` when `User::isAdmin()`. A token's restaurants are
`User::accessibleRestaurants()`; every route below is
`/api/v1/operator/restaurants/{restaurant}/...` behind `auth:sanctum`,
`ability:operator`, `tenant.route`, and a new `operator.access` middleware
that mirrors `ResolveAdminRestaurant` + `RequireRestaurantAdmin` for JSON
(404 when the user cannot access the restaurant; staff vs admin role
matters for settings). Do **not** relax `tenant.route`'s live-only rule for
operators blindly — an onboarding restaurant must be reachable by its owner
here, so add a `?preview`-free variant: operators may resolve any
non-suspended restaurant they belong to.

Endpoints (each wraps an existing controller's service call; reuse the form
request and DTO where one exists):

- `GET /me/restaurants` — `RestaurantSummaryData[]` the token can operate
  (`accessibleRestaurants()`), with role.
- Orders: `GET orders` (filters mirror `Admin\TenantAdmin\OrdersController@index`:
  status[], search, date range; `OrderSummaryData` + meta), `GET orders/{order}`
  (`OrderData` + events + delivery + payment state), `POST orders/{order}/transition`
  (`OrderTransition::apply()`, same validation as `@transition`; 409 on
  `InvalidOrderTransitionException`), `GET kitchen` (the board query from
  `KitchenController@index`, poll-friendly, add `since=` cursor).
- Menu: categories CRUD + reorder (`MenuCategoryController`), items CRUD +
  reorder + availability toggle (`MenuController`, `MenuItemObserver` handles
  images), ingredients (`MenuItemIngredientController` → `IngredientEditor::sync()`),
  templates (`ItemTemplateController`), swap sets (`SwapSetController`),
  "apply to category" (`IngredientEditor::applyRules()`). Item images: accept
  multipart, reuse `PhotoConversionService`.
- Hours (`HoursController`), settings subset (`RestaurantSettingsRequest`;
  admin role only), delivery + POS integration *status* (read-only; connecting
  stays on the web because it is OAuth redirects).
- Customers: `GET customers` (`CustomersController@index` query,
  `CustomerData` + `CustomerStatsData`), export stays web.
- Push for operators (later): "new order" to operator devices — reuse
  `device_tokens` + `ExpoPushChannel`; add `users.push_new_orders`.

Tests: an `operator` matrix (customer token → 403; staff vs admin on
settings; restaurant the user can't access → 404; suspended → 404); every
endpoint's happy path; transitions reuse `OrderTransitionTest` fixtures.
Add the new DTOs to `API_V1_CONTRACT`.

## Phase 2 — Machine auth: API keys (~1 session + the ⚑ decision)

- Migration `api_keys` (restaurant_id, name, key_prefix, key_hash, scopes
  json, last_used_at, revoked_at, created_by_user_id). Key shown once on
  creation (`pfk_live_…`), stored hashed (SHA-256), looked up by prefix.
- Guard: a small `ApiKeyGuard` registered as `auth:api-key` (or resolve the
  key in middleware and set a synthetic principal). Scopes: `orders:read`,
  `orders:write`, `menu:read`, `menu:write`, `webhooks:manage`. Reuse the
  `ability`/`abilities` middleware semantics by mapping scopes onto them.
- Management UI on the tenant admin Settings page (create / revoke / list),
  admin role only; and `GET/POST/DELETE /api/v1/operator/restaurants/{r}/api-keys`.
- Limiter `api-key` per key (300/min ⚑), 401 for revoked, audit
  `last_used_at`.
- Tests: key auth on every operator route; scope enforcement; revoked key;
  key of restaurant A on restaurant B's URL → 404.

## Phase 3 — Outbound webhooks (~2 sessions)

- Migration `webhook_endpoints` (restaurant_id, url, secret, events json,
  enabled, last_success_at, last_failure_at) and `webhook_deliveries`
  (endpoint_id, event, payload json, attempt, status, response_code,
  next_attempt_at, delivered_at).
- Events: `order.created` (from `OrderPlacement::materialize()`),
  `order.status_changed` (from `OrderTransition::apply()`),
  `delivery.status_changed` (from `DeliveryAssignmentObserver`),
  `menu_item.availability_changed` (from `MenuItemObserver`). Payloads are
  the existing DTOs (`OrderData`, `DeliveryAssignmentData`, `MenuItemData`)
  wrapped `{id, type, created_at, data}`.
- `WebhookDispatcher` service + `DeliverWebhook` queued job with backoff,
  HMAC-SHA256 `Plateful-Signature: t=…,v1=…` (same scheme we verify from
  Stripe, so integrators can copy `StripeWebhookController`'s check), 10s
  timeout, auto-disable after N consecutive failures with an email to the
  owner.
- Management: endpoints CRUD via the operator API and the Settings page;
  `POST .../webhook-endpoints/{id}/test` sends a `ping`.
- Tests: `Http::fake` the endpoint, assert signature, retries via
  `Queue::fake`, disable-after-failures, per-restaurant isolation.

## Phase 4 — Docs, idempotency, contract (~1–2 sessions)

- ⚑ Scramble → OpenAPI at `/docs/api` (primary host, no auth), plus a
  Postman/Insomnia export in `docs/`. The DTO snapshot stays the source of
  truth for shapes; Scramble reads the same classes.
- `Idempotency-Key` header on operator/machine writes: `idempotency_keys`
  table (key, scope, request hash, response, expires_at); replay returns
  the stored response, mismatched body → 422.
- Error shape: keep Laravel's `{message, errors}`; add a stable `code` for
  the domain errors we throw (`InvalidCheckoutException`,
  `InvalidOrderTransitionException`, `InvalidCartSelectionException`).
- Versioning note in the docs: `/v1` additive-only; breaking → `/v2`.

## Sequencing triggers

1. Phase 1 as soon as an operator app is scheduled — it needs nothing
   external and unblocks the "operator app" item in the app plan's "Later".
2. Phases 2–4 only when a named third party wants in; keys without a
   consumer are maintenance.
3. Decide the backward-compat promise before the first outside key.

## Gotchas carried over from the customer API build

- Route model bindings on `/restaurants/{restaurant}/...` resolve *inside*
  the tenant only because `ResolveTenantFromRoute` is prepended to the
  priority list before `SubstituteBindings`. Keep any new tenant middleware
  on the same footing.
- In tests, `withHeader()`/`withToken()` persist for the whole test and the
  Sanctum guard memoises its user; use `forgetApiGuards()` between
  identities. `CurrentTenant` also persists between requests in one test.
- `Http::preventStrayRequests()` is global; fake every outbound call.
- `GOOGLE_MAPS_API_KEY`/`GOOGLE_GEOCODING_API_KEY` are blanked in
  `phpunit.xml` on purpose (the local `.env` has real ones).
- Full suite: `"$PHP" -d memory_limit=2G vendor/bin/pest --compact`
  (see `reference_herd_php` memory); Pint: `vendor/bin/pint --dirty --format agent`
  through Herd's PHP.
