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
  `menu:read`, `menu:write`, `customers:read`, `api-keys:manage`, and the
  platform-only `platform:read` (added 2026-09-14 with the earnings tools:
  `api-key:create "Reports" --platform --scopes=platform:read` mints a
  read-only platform key; `*` implies it; restaurant keys can never hold it,
  and `ApiActor::hasScope()` refuses platform-only scopes to non-platform
  actors even if a row somehow carried one).
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
| `POST/DELETE restaurants/{r}/menu-items/{id}/image` | menu:write | `MenuItemData`; multipart `image` or `source_url` |
| `POST/DELETE restaurants/{r}/images/{logo\|hero\|about}` | restaurants:write | `RestaurantImagesData` (replaces the slot; old variants deleted) |
| `GET restaurants/{r}/photos` | restaurants:read | `RestaurantPhotoData[]` gallery in order |
| `POST restaurants/{r}/photos`, `DELETE photos/{id}` | restaurants:write | `RestaurantPhotoData` (201, optional `caption`) / 204 |
| `GET platform/earnings` | platform:read | `EarningsSummaryData` (payout sheet for `?month=YYYY-MM`, default current) |
| `GET platform/earnings/restaurants` | platform:read | `RestaurantEarningsData[]` per restaurant for the month: orders, food, gross fee, commission vs cap, delivery margin, ledger total |
| `GET platform/earnings/ledger` | platform:read | `FeeDistributionData[]` + meta; filters `restaurant`, `user` (id or email), `order`, `role`, `month` or `from`/`to`, `include_refunded` |

Shared query classes so REST and MCP never drift: `App\Support\Operator\
OperatorOrders` (paginate, board, find by id-or-number, statusCounts),
`App\Support\Customers\CustomersQuery` (extracted from the tenant admin
`CustomersController`, which now delegates), and `App\Support\Platform\
EarningsQuery` (payout summary, per-restaurant breakdown, ledger; the
super-admin `EarningsController` now delegates to it), and `App\Support\
Operator\OperatorImages` (menu item / logo / hero / about / gallery writes
through `RestaurantImageService`, plus `fromUrl()` / `fromBase64()` that turn
a fetched or decoded image into an `UploadedFile` validated against
`PhotoConversionService::acceptedPhotoMimes()`, 8 MB cap). `KitchenController`
reads `OperatorOrders::BOARD_STATUSES`.

### MCP — `POST https://plateful.fyi/mcp/platform`

`routes/ai.php`, `App\Mcp\Servers\PlatformServer` (laravel/mcp v0.7, already
a dependency), guarded by `auth:api-key` + the operator limiter. Tools, each
taking the restaurant **subdomain**: `list-restaurants`, `get-restaurant`,
`list-orders`, `get-order` (number or id), `kitchen-board`,
`transition-order`, `list-customers`, `get-menu`, `set-menu-item-availability`,
`list-gallery-photos`, `upload-image` (target `menu_item|logo|hero|about|gallery`,
image as `source_url` or `image_base64` since MCP has no multipart),
`remove-image`, and the platform-only `earnings-summary`, `earnings-by-restaurant`,
`earnings-ledger` (gated on `platform:read` via `OperatorTool::platform()`).
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
`OrderEventData`, `ApiKeyData`, `ApiKeyCreatedData`; platform reports:
`EarningsSummaryData`, `EarnerData`, `RestaurantEarningsData`,
`FeeDistributionData`; images: `RestaurantImagesData`, `RestaurantPhotoData`.
Enums `ApiKeyScope` (now also `restaurants:write`, admin role only), `RestaurantImageKind`.

### Tests

`tests/Feature/Api/V1/Operator/{ApiKeyAuthTest,OperatorOrdersTest,
OperatorMenuCustomersTest,OperatorApiKeysTest}.php`,
`tests/Feature/Api/V1/Operator/PlatformEarningsTest.php` + fixture in
`EarningsTestHelpers.php`, `tests/Feature/Mcp/PlatformEarningsToolsTest.php`,
`tests/Feature/Mcp/PlatformServerTest.php` (tool matrix via
`PlatformServer::actingAs($key, 'api-key')->tool(...)`, plus a real HTTP
`initialize` + `tools/list` round trip). Helpers in
`tests/Feature/Api/V1/ApiHelpers.php`: `operatorTokenFor()`, `apiKeyFor()`,
`operatorUrl()`.

### Connect page, per-key rate limit, audit log (2026-09-15) — `docs/mcp.md`

- **Admin page** `admin.<domain>/<sub>/settings/ai` (Manage → AI assistant,
  restaurant admins): `TenantAdmin\AiAssistantController` mints a restaurant
  key (scopes pre-ticked: restaurants r/w, menu r/w, orders read; rate limit
  default 60/min), flashes the plaintext for one page load with the setup
  for Claude Code (header), claude.ai and ChatGPT (connector URL), lists and
  revokes keys, shows the last 50 audit rows. `AiAssistantKeyRequest`
  extends the REST `ApiKeyStoreRequest`.
- **Connector URL** `POST /mcp/platform/{connectKey}` (`mcp.platform.connect`,
  `Route::pattern` `pfk_[A-Za-z0-9_]+`): the `api-key` guard reads the key
  from the path when there is no bearer header, for clients that only take a
  URL. Same server, same limiter.
- **Per-key rate limit** `api_keys.rate_limit_per_minute` (nullable, 1–300;
  null = `ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE` 300). `api-operator` reads
  it off the principal; REST `rate_limit_per_minute`, CLI `--rate-limit=`,
  `ApiKeyData.rateLimitPerMinute` (additive, snapshot updated).
- **Audit log** `api_call_logs` / `ApiCallLog` / `ApiCallLogger`:
  `PlatformServer::runMethodHandle()` wraps `tools/call`; `LogOperatorApiCall`
  (`operator.log`) on the REST group, on the priority list before
  `ResolveOperatorRestaurant` (anything off the list sorts *after* every
  priority middleware, so refusals were invisible until it was added).
  Arguments redacted (`image_base64`, `source_url`, >200-char strings).
  Restaurant attribution ignores a stale `CurrentTenant` unless it matches
  the named/routed restaurant. `ApiCallLogData` (page only, not in the v1 contract).
- Tests: `tests/Feature/Mcp/{McpAuditLogTest,McpRateLimitTest,McpConnectUrlTest}.php`,
  `tests/Feature/Admin/AiAssistantPageTest.php`.

## Phase 1b — the rest of the operator surface (when the operator app is scheduled)

Every item wraps an existing tenant-admin controller's service call; reuse
the form request and DTO where one exists, add the REST route *and* the MCP
tool, and add each new DTO to `API_V1_CONTRACT`.

- ~~Images~~ (done 2026-09-14: menu item photo, logo/hero/about, gallery).
- Menu writes: categories CRUD + reorder (`MenuCategoryController`), items
  CRUD + reorder (`MenuController`),
  ingredients (`MenuItemIngredientController` → `IngredientEditor::sync()`,
  which lives in `app/Support/Menus`), templates, swap sets, apply-to-category.
- Hours (`HoursController`), settings subset (`RestaurantSettingsRequest`,
  admin role), delivery + POS integration *status* (read-only).
- Customers export stays web. Push "new order" to operator devices later
  (reuse `device_tokens` + `ExpoPushChannel`).
- Platform-only tools for Claude as needs surface: ~~earnings/attribution~~
  (done 2026-09-14), restaurant lifecycle reads (pending review / approved /
  suspended with dates and Stripe state), campaign review queue, delivery +
  POS integration status across restaurants, menu import status, users.
  Pattern: a query class in `app/Support/Platform`, a DTO in the contract, a
  REST route under `operator/platform/...` behind `operator.scope:platform:read`,
  and an MCP tool calling `$this->platform($request, ApiKeyScope::PlatformRead)`.
- ~~Settings-page UI for restaurant keys~~ (done 2026-09-15: the AI
  assistant page, `docs/mcp.md`).

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
- Earnings month windows use the app timezone (like the super-admin page);
  only `capRemainingCents` uses the restaurant's local current month via
  `MonthlyCommissionCap`, and it is null for any month but the current one.
- `UploadedFile::fake()->image()` deletes its temp file when the object is
  garbage-collected: assign it to a variable before reading the bytes.
- `Http::fake()` patterns match in order; put a specific URL before a wildcard.
- Middleware not on the priority list sorts *after* every middleware that
  is (`SortedMiddleware`), whatever order the group lists them in. A
  middleware that must wrap `operator.restaurant` / `operator.scope` has to
  be added to the priority list itself (see `LogOperatorApiCall`).
- Browser tests (pest-plugin-browser) run an in-process server on
  `127.0.0.1:<port>` and only *spoof* the Host header, so an absolute URL to
  `admin.plateful.test` (or whatever host Wayfinder baked in from `.env` at
  build time) never reaches it: a form posting to a Wayfinder `url()` or a
  controller redirecting with `redirect()->route()` silently fails (status
  0). Pages that must work there post to a path prop
  (`route(..., absolute: false)`) and controllers return `back()`, like the
  AI assistant page.
- `laravel/pao` captures test stdout when it detects an agent; a fatal at
  collection (e.g. extending a `final` class in a test) then shows as a bare
  exit 2 with no output. `PAO_DISABLE=1` does not help there; `php -l`
  and reading the file do.
- `assertJsonPath` compares strictly and JSON drops `.0`, so a float DTO
  field that happens to be whole (e.g. `feePercent` 4.0) needs `toEqual`.
- Editing files with `perl -pi` and `\x{00a7}`-style escapes writes a raw
  0xA7 byte, not UTF-8 `§`; use python or a literal character.
- `MenuItemData::fromModel()` lazy-loads templates/ingredients when they are
  not loaded; fine for one item, eager-load for lists.
- `withToken()` persists for the whole test and guards memoise; call
  `forgetApiGuards()` between identities. `Http::preventStrayRequests()` is
  global. Full suite: `"$PHP" -d memory_limit=2G vendor/bin/pest --compact`;
  Pint through Herd's PHP.
