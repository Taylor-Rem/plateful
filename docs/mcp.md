# Connect an AI assistant to Plateful (MCP)

_Written 2026-09-15. Plateful runs a remote [MCP](https://modelcontextprotocol.io)
server so a restaurant can use the assistant it already pays for (Claude,
ChatGPT, Claude Code) on its own account: read orders, edit the menu, mark
items sold out, change photos. It costs the restaurant nothing beyond its
assistant's own plan. The concierge tier (text us, we do it) is the relay;
this page is the bring-your-own-assistant tier._

## Where it is

| | |
|---|---|
| Endpoint | `POST https://plateful.fyi/mcp/platform` (MCP streamable HTTP) |
| Auth | `Authorization: Bearer pfk_live_…` — a Plateful API key |
| Connector URL | `https://plateful.fyi/mcp/platform/<key>` for clients that cannot send a header (see below) |
| Code | `routes/ai.php`, `App\Mcp\Servers\PlatformServer`, `app/Mcp/Tools/*` |

Every tool takes the restaurant's **subdomain** as `restaurant` (from
`list-restaurants`). Money is integer cents, timestamps ISO 8601 UTC. The
server instructions tell the assistant to confirm with the person before
any write.

## Tools

| Tool | Scope | Does |
|---|---|---|
| `list-restaurants` | — | the restaurants this key can reach |
| `get-restaurant` | restaurants:read | profile, hours, photos |
| `list-orders`, `get-order`, `kitchen-board` | orders:read | orders (filters: status, search, dates), one order with events, the live board |
| `transition-order` | orders:write | move an order pending → confirmed → preparing → ready → completed / cancelled |
| `list-customers` | customers:read | the customer list |
| `get-menu` | menu:read | full menu, hidden items included |
| `set-menu-item-availability` | menu:write | mark an item available / sold out |
| `upload-image` | menu:write (menu item) / restaurants:write (logo, hero, about, gallery) | set a photo from a URL or base64 |
| `remove-image`, `list-gallery-photos` | as above / restaurants:read | remove a photo; list the gallery |
| `earnings-summary`, `earnings-by-restaurant`, `earnings-ledger` | platform:read | platform reports; platform keys only |

The same operations exist as REST under `/api/v1/operator/…`
(`docs/plateful_platform_api_plan.md`), and `pf` wraps them for sessions on
this machine. A key can do nothing over MCP it could not do over REST:
both go through `App\Support\Api\ApiActor`.

## Keys and permissions

A key is minted **once** and only its SHA-256 is stored; the plaintext is
shown at creation and never again. Three ways to get one:

- **Admin console → Manage → AI assistant** (`admin.plateful.fyi/<sub>/settings/ai`,
  restaurant admins only): pick a name, the permissions, a requests-per-minute
  ceiling; the page shows the key with the setup for each client, lists and
  revokes keys, and shows recent activity. This is what a restaurant uses.
- `pf keys create -r <sub> --name Claude --scopes menu:read,menu:write,… --save KEY_NAME`
  (operator REST, needs a key with `api-keys:manage`).
- `php artisan api-key:create "Claude" --restaurant=<sub> [--scopes=…] [--rate-limit=60] [--expires=…]`
  on the server; `--platform` mints a platform key (super-admin reach), which
  the admin page never does.

| Scope | Grants |
|---|---|
| `restaurants:read` | read profile and hours |
| `restaurants:write` | change logo, hero, about and gallery images |
| `menu:read` | read the full menu, hidden items included |
| `menu:write` | change item availability, set item photos |
| `orders:read` | read orders and the kitchen board |
| `orders:write` | move orders between statuses |
| `customers:read` | read the customer list |
| `api-keys:manage` | create and revoke this restaurant's keys (REST) |
| `platform:read`, `*` | platform keys only; never on a restaurant key |

The admin page pre-ticks `restaurants:read/write`, `menu:read/write`,
`orders:read`: an assistant that can keep the menu and photos right and see
what's cooking, but not move orders or read customer contact details unless
you say so.

**Revoking** (page, `pf keys revoke`, or REST `DELETE …/api-keys/{id}`) is
immediate: the next call with that key gets `401`.

## Connecting each client

**Claude Code** (the endpoint's `initialize` → `tools/list` → `tools/call`
round trip is exercised over HTTP in the test suite; this is the command
Claude Code documents for a remote HTTP server with a header):

```
claude mcp add --transport http plateful https://plateful.fyi/mcp/platform \
  --header "Authorization: Bearer pfk_live_…"
```

**claude.ai**: Settings → Connectors → *Add custom connector*. Name it
Plateful, paste the connector URL
`https://plateful.fyi/mcp/platform/pfk_live_…`, leave the OAuth client
fields empty. Custom connectors take a bare URL and cannot add a header,
which is why the key rides in the path.

**ChatGPT**: Settings → Connectors → *Create* (turn on Developer mode under
Advanced if you don't see it). Name it Plateful, paste the same connector
URL, Authentication: *No authentication*.

The connector URL **is** the key. Treat it like a password: anyone holding
it can do whatever that key allows. If it leaks, revoke the key on the AI
assistant page and make another. A header still wins over the path when
both are present.

## Rate limit

One bucket per key, shared by MCP and REST (`throttle:api-operator`):

- default **300 requests/min** (`ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE`);
- a key may carry its own lower ceiling (`rate_limit_per_minute`, 1–300).
  The admin page defaults new keys to **60/min**; `--rate-limit=` and the
  REST `rate_limit_per_minute` field set it elsewhere;
- over the limit → `429` with `Retry-After`; the usual `X-RateLimit-*`
  headers are on every response.

Signed-in operators (Sanctum `operator` tokens) always get the default.

## Audit log

Every MCP tool call and every operator REST request is one row in
`api_call_logs` (`App\Models\ApiCallLog`, written by
`App\Support\Api\ApiCallLogger`): who (key or user), which restaurant, the
tool or route name, the arguments (image payloads and URLs replaced by a
size note, long strings truncated), whether it succeeded, the error text
when it didn't, and the duration. Refused calls (wrong restaurant, missing
scope, 404) are logged too; unauthenticated and rate-limited requests never
reach the log. A restaurant key's calls file under its own restaurant even
when it named another, so the owner sees the attempt.

The AI assistant page shows the last 50 rows for the restaurant. There is
no retention job yet; rows accumulate (one row ≈ 300 bytes).

Where it hooks in: `PlatformServer::runMethodHandle()` wraps `tools/call`;
`LogOperatorApiCall` (`operator.log`) sits on the REST group, placed on the
middleware priority list ahead of `operator.restaurant` / `operator.scope`
so their refusals are recorded.

## Tests

```
php artisan test --compact --filter="Mcp|AiAssistant|OperatorApiKeys|ApiKeyAuth"
```

`tests/Browser/AiAssistantPageTest.php` drives the page in Chromium: fill,
create, the one-time panel with all three setups (needs
`npx playwright install chromium` once locally; CI has it).
`tests/Feature/Mcp/McpAuditLogTest.php` (rows for MCP and REST, refusals,
redaction), `McpRateLimitTest.php` (per-key ceiling, default, REST + CLI
input), `McpConnectUrlTest.php` (key in the path, revoked/unknown/malformed,
header precedence), `tests/Feature/Admin/AiAssistantPageTest.php` (page,
staff forbidden, mint → shown once → key works, validation, revoke → 401,
other restaurant's key → 404), plus the Phase 1 suites listed in
`docs/plateful_platform_api_plan.md`.
