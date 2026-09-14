# Plateful platform API — plan (operator API, machine auth, MCP, outbound webhooks, docs)

_Drafted 2026-09-14 as a hand-off; decisions taken and Phase 1 built the same
day. Status: **Phase 1 shipped (keys, guard, operator REST subset, MCP
server); Phases 2–4 open.** Builds on the customer API shipped 2026-09-11
(`docs/plateful_app_plan.md`, live on `plateful.fyi/api/v1`)._

## Decisions (Taylor, 2026-09-14)

- **First consumer is Claude on live data**, not the operator app. The
  operator app is welcome later on the same surface. So machine credentials
  came *first*, and the surface Claude uses is a **remote MCP server** plus
  the REST endpoints that wrap the same queries.
- **Credentials: restaurant-scoped API keys *and* platform keys.** One
  `api_keys` table; `restaurant_id NULL` = platform key with super-admin
  reach ("like how super admins have complete access to everything"). No
  Passport; keys are hashed rows behind a tiny guard.
- **Reads + scoped writes from day one.** Every write is a named scope
  (`orders:write`, `menu:write`) and the MCP tool descriptions tell the
  agent to confirm with the person first.
- **Docs generator: Scramble** (new dependency, approved) — Phase 4.
- **`/v1` is additive-only, publicly**, same class of promise as the 4% and
  the $399 cap. Enforced by the `ContractTest` snapshot.
- Webhook guarantees (at-least-once, HMAC, backoff) recommended, not yet
  needed — Phase 3.

## What shipped (Phase 1, 2026-09-14) — 37 new tests, full suite green

### Credentials

- `api_keys` (`restaurant_id` nullable, `name`, `key_prefix`, `key_hash`
  sha256 unique, `scopes` json, `last_used_at`, `expires_at`, `revoked_at`,
  `created_by_user_id`). `ApiKey::mint()` returns the plaintext once:
  `pfk_live_…` in production, `pfk_test_…` elsewhere (49 chars).
- `ApiKeyScope` enum: `*`, `restaurants:read`, `orders:read`, `orders:write`,
  `menu:read`, `menu:write`, `customers:read`, `api-keys:manage`.
  `forRole()` maps pivot roles onto scopes for signed-in people (staff:
  restaurants/orders read, orders write, menu read; admin: everything but `*`).
- Guard `api-key` (`Auth::viaRequest` in `AppServiceProvider`, config in
  `config/auth.php`); `ApiKey` is an `Authenticatable`. Revoked/expired keys
  are simply not found → 401. `last_used_at` touched at most once a minute.
- `php artisan api-key:create "Claude" --platform [--user=email]` mints a
  platform key; `--restaurant=<subdomain> --scopes=orders:read …` a
  restaurant key; `--expires=`. Prints the key once.
- Sanctum tokens issued by `POST /auth/login` now carry `operator` alongside
  `customer` whenever `User::isAdmin()` (`ApiTokenIssuer::abilitiesFor`).

### The actor abstraction (read this before adding an endpoint or tool)

`App\Support\Api\ApiActor` wraps whichever principal signed the request
(a `User` whose token has `operator`, or an `ApiKey`) and answers:
`canAccessRestaurant()` (platform actors reach everything, suspended
included; others their own non-suspended restaurants), `hasScope(scope,
restaurant)`, `restaurants()`, `auditUser()` (null for keys — order events
made by a key have no user). REST middleware and MCP tools both use it, so a
key can never do over MCP what it cannot do over HTTP.

Middleware (aliases in `bootstrap/app.php`):
`auth:sanctum,api-key` → `operator` (`AuthenticateOperator`: 403 for
customer-only tokens) → `operator.restaurant` (`ResolveOperatorRestaurant`:
404 for anything the actor cannot reach, sets `CurrentTenant`) →
`operator.scope:<scope>` (`RequireApiScope`: 403). The last two are
prepended to the priority list **before `SubstituteBindings`**, so the tenant
is set before `{order:number}` / `{menuItem}` bind and a scope-less
credential is refused before any id lookup can leak existence.

### REST — `/api/v1/operator/…` (primary host, `throttle:api-operator` 300/min per principal)

| Route | Scope | Returns |
|---|---|---|
| `GET me` | — | `OperatorActorData` (type, name, isPlatform, scopes, restaurants with role + scopes) |
| `GET restaurants` | — | `OperatorRestaurantData[]` |
| `GET restaurants/{r}` | restaurants:read | `RestaurantData` (+hours, photos) |
| `GET restaurants/{r}/orders` | orders:read | `OrderSummaryData[]`, meta + `statusCounts`; filters `status[]`, `search`, `from`, `to`, `since`, `page`, `per_page` |
| `GET restaurants/{r}/orders/{number}` | orders:read | `OperatorOrderData` (order, paymentState, refund, POS push, events newest-first) |
| `POST restaurants/{r}/orders/{number}/transition` | orders:write | same; `to_status`, `note`; 422 on illegal move |
| `GET restaurants/{r}/kitchen` | orders:read | `OrderData[]` board (pending→ready), `meta.asOf`; `since=` cursor |
| `GET restaurants/{r}/menu` | menu:read | `MenuCategoryData[]` hidden included |
| `PATCH restaurants/{r}/menu-items/{id}/availability` | menu:write | `MenuItemData` |
| `GET restaurants/{r}/customers` | customers:read | `CustomerData[]` + meta; `search`, `ordered=30|90`, `marketing=opted_in`, `sort`, `dir` |
| `GET/POST/DELETE restaurants/{r}/api-keys[/{id}]` | api-keys:manage | `ApiKeyData[]` / `ApiKeyCreatedData` (plaintext once) / 204 |

Shared query classes so REST and MCP never drift: `App\Support\Operator\
OperatorOrders` (paginate, board, find by id-or-number, statusCounts) and
`App\Support\Customers\CustomersQuery` (extracted from the tenant admin
`CustomersController`, which now delegates). `KitchenController` reads
`OperatorOrders::BOARD_STATUSES`.

### MCP — `POST https://plateful.fyi/mcp/platform`

`routes/ai.php`, `App\Mcp\Servers\PlatformServer` (laravel/mcp v0.7, already
a dependency), guarded by `auth:api-key` + the operator limiter. Tools, each
taking the restaurant **subdomain**: `list-restaurants`, `get-restaurant`,
`list-orders`, `get-order` (number or id), `kitchen-board`,
`transition-order`, `list-customers`, `get-menu`, `set-menu-item-availability`.
Read tools carry `readOnlyHint`; the server instructions tell the agent to
confirm before writes. Errors (unknown restaurant, missing scope, illegal
transition, validation) come back as tool errors, not exceptions.

Connect Claude Code:

```
claude mcp add --transport http plateful https://plateful.fyi/mcp/platform \
  --header "Authorization: Bearer pfk_live_…"
```

Mint the key from the Laravel Cloud environment's Commands tab:
`php artisan api-key:create "Claude" --platform --user=<your email>`.
Keep the key out of chat and transcripts (see `project_secret_hygiene`).

### DTOs added to `API_V1_CONTRACT` (snapshot updated, `generated.d.ts` regenerated)

`OperatorActorData`, `OperatorRestaurantData`, `OperatorOrderData`,
`OrderEventData`, `ApiKeyData`, `ApiKeyCreatedData`. Enum `ApiKeyScope`.

### Tests

`tests/Feature/Api/V1/Operator/{ApiKeyAuthTest,OperatorOrdersTest,
OperatorMenuCustomersTest,OperatorApiKeysTest}.php`,
`tests/Feature/Mcp/PlatformServerTest.php` (tool matrix via
`PlatformServer::actingAs($key, 'api-key')->tool(...)`, plus a real HTTP
`initialize` + `tools/list` round trip). Helpers in
`tests/Feature/Api/V1/ApiHelpers.php`: `operatorTokenFor()`, `apiKeyFor()`,
`operatorUrl()`.

## Phase 1b — the rest of the operator surface (when the operator app is scheduled)

Every item wraps an existing tenant-admin controller's service call; reuse
the form request and DTO where one exists, add the REST route *and* the MCP
tool, and add each new DTO to `API_V1_CONTRACT`.

- Menu writes: categories CRUD + reorder (`MenuCategoryController`), items
  CRUD + reorder (`MenuController`, `MenuItemObserver` handles images),
  ingredients (`MenuItemIngredientController` → `IngredientEditor::sync()`,
  which lives in `app/Support/Menus`), templates, swap sets, apply-to-category.
- Hours (`HoursController`), settings subset (`RestaurantSettingsRequest`,
  admin role), delivery + POS integration *status* (read-only).
- Customers export stays web. Push "new order" to operator devices later
  (reuse `device_tokens` + `ExpoPushChannel`).
- Platform-only tools for Claude as needs surface: earnings/attribution
  reads, restaurant lifecycle reads, campaign review queue.
- Settings-page UI for restaurant keys (create / revoke / list; admin role).
  The REST endpoints exist; only the Vue page is missing.

## Phase 3 — Outbound webhooks (~2 sessions, when a third party wants them)

- Migration `webhook_endpoints` (restaurant_id, url, secret, events json,
  enabled, last_success_at, last_failure_at) and `webhook_deliveries`
  (endpoint_id, event, payload json, attempt, status, response_code,
  next_attempt_at, delivered_at).
- Events: `order.created` (from `OrderPlacement::materialize()`),
  `order.status_changed` (from `OrderTransition::apply()`),
  `delivery.status_changed` (from `DeliveryAssignmentObserver`),
  `menu_item.availability_changed` (from `MenuItemObserver`). Payloads are
  the existing DTOs wrapped `{id, type, created_at, data}`.
- `WebhookDispatcher` + `DeliverWebhook` queued job, backoff 1m/5m/30m/2h/12h,
  HMAC-SHA256 `Plateful-Signature: t=…,v1=…` (Stripe's scheme, so integrators
  can copy `StripeWebhookController`'s check), 10s timeout, auto-disable after
  N consecutive failures with an email to the owner.
- Management via the operator API (scope `webhooks:manage`, add to the enum)
  and the Settings page; `POST …/webhook-endpoints/{id}/test` sends a `ping`.
- Tests: `Http::fake` the endpoint, assert signature, retries via
  `Queue::fake`, disable-after-failures, per-restaurant isolation.

## Phase 4 — Docs, idempotency, contract (~1–2 sessions)

- Scramble (`dedoc/scramble`, approved) → OpenAPI at `/docs/api` on the
  primary host, no auth; Postman export in `docs/`. The DTO snapshot stays
  the source of truth for shapes.
- `Idempotency-Key` header on operator writes: `idempotency_keys` table
  (key, scope, request hash, response, expires_at); replay returns the stored
  response, mismatched body → 422.
- Error shape: keep Laravel's `{message, errors}`; add a stable `code` for
  the domain errors (`InvalidCheckoutException`,
  `InvalidOrderTransitionException`, `InvalidCartSelectionException`).
- Versioning note in the docs: `/v1` additive-only; breaking → `/v2`.
- Non-blocking: IP allow-lists per key, a `sandbox` mode (test restaurants +
  test Stripe) for integrators.

## Gotchas (learned building Phase 1, plus the ones carried over)

- **Scope before bindings.** `RequireApiScope` reads the restaurant from
  `CurrentTenant`, not the route, because it runs before `SubstituteBindings`.
  A new tenant-aware middleware must be prepended to the priority list the
  same way or bindings will run first.
- `abilities:` (Sanctum) requires *all* listed abilities; `ability:` any.
  Customer routes keep `abilities:customer`, which operator tokens also hold.
- The `api` and `api-operator` limiters key by `class_basename:id` so user #1
  and key #1 never share a bucket.
- `Auth::viaRequest` guards need a `provider` in `config/auth.php` even
  though the callback ignores it.
- laravel/mcp's `Request::user()` reads the *default* guard; `auth:api-key`
  on the route calls `shouldUse`, and tests must pass the guard to
  `actingAs($key, 'api-key')`.
- `Response::error()` inside a tool is the right way to fail; only
  `AuthenticationException`, `AuthorizationException` and
  `ValidationException` are converted for you.
- `MenuItemData::fromModel()` lazy-loads templates/ingredients when they are
  not loaded; fine for one item, eager-load for lists.
- `withToken()` persists for the whole test and guards memoise; call
  `forgetApiGuards()` between identities. `Http::preventStrayRequests()` is
  global. Full suite: `"$PHP" -d memory_limit=2G vendor/bin/pest --compact`;
  Pint through Herd's PHP.
