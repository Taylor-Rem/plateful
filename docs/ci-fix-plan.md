# Plan: Get CI green on `dev`

_Written 2026-07-31 from a full diagnosis session. Self-contained — no prior context needed._

**Goal:** both GitHub workflows (`tests.yml`, `linter` in `lint.yml`) pass on `dev`. CI has been
red on every run since the 2026-07-15 workflow gate fix. The product code is healthy — the full
suite is green locally (**1019 tests, 1017 passed, 2 skipped**, run with
`"$HOME/Library/Application Support/Herd/bin/php84" -d memory_limit=2G vendor/bin/pest --compact`).
Every CI failure is environment/lint drift, verified against runs `30479599072` (tests) and
`30479598858` (linter) on 2026-07-29 (`gh run view <id> --log-failed`).

There are three independent causes. Fix and verify each on its own; none depends on another.

---

## Cause 1 — linter workflow: 22 ESLint `import/order` errors

`lint.yml` runs the check variants (`npm run lint:check` etc. — deliberately, see the comment at
the top of that file; do NOT switch it back to fixers). Prettier and Pint are clean; ESLint fails
with exactly 22 `import/order` errors across Vue pages (2FA pages, super-admin Users/Restaurants
pages, onboarding, marketing pages — all from recent feature work).

**Fix:**

```bash
npx eslint . --fix
npm run format          # prettier may re-wrap what eslint reordered
npm run lint:check && npm run format:check && npm run types:check
```

All 22 are auto-fixable; expect zero manual edits. `types:check` afterward guards against an
import reorder breaking a `type` import.

## Cause 2 — tests workflow: `.env.example` has no `DOORDASH_*` entries

`tests.yml` does `cp .env.example .env` (line 49). `.env.example` contains **zero** `DOORDASH_*`
lines, so in CI `config('services.doordash.developer_id')` etc. are empty.

**Failing test:** `Tests\Feature\Storefront\DoorDashDeliveryMoneyTest` → "it quotes a grossed-up
delivery fee for a DoorDash restaurant". It exercises the live quote path
(`POST /checkout/delivery-quote`), and `DoorDashJwtService::mint()`
(app/Services/Delivery/DoorDash/DoorDashJwtService.php:44) throws
`DeliveryProviderException::notConfigured` when any of `developer_id` / `key_id` /
`signing_secret` is empty — the request dies before the test's `Http::fake` ever matters. The
file's other three tests pass in CI because they use a pre-stored `DeliveryQuote` + `Queue::fake`
and never mint a JWT. It passes locally only because the developer `.env` holds real sandbox creds
— i.e. a fresh clone without creds fails this test locally too.

**Fix (do both):**

1. **Pin fake creds in `phpunit.xml`** so the suite never depends on `.env` contents:

   ```xml
   <env name="DOORDASH_DEVELOPER_ID" value="test-developer-id" force="true"/>
   <env name="DOORDASH_KEY_ID" value="test-key-id" force="true"/>
   <env name="DOORDASH_SIGNING_SECRET" value="dGVzdC1zaWduaW5nLXNlY3JldA" force="true"/>
   <env name="DOORDASH_WEBHOOK_SECRET" value="test-webhook-secret" force="true"/>
   ```

   The signing secret is run through `base64UrlDecode` (DoorDashJwtService.php:70), so it must be
   valid base64url — the value above decodes to `test-signing-secret`. Safety checked: there is
   **no DoorDash live-sandbox suite** (only `UberDirectLiveSandboxTest`, `SquareLiveSandboxTest`,
   `CloverLiveSandboxTest`, which key off separate `*_SANDBOX_*` vars), so forcing fake platform
   creds in tests breaks nothing. Check whether `DoorDashWebhookTest` sets its own webhook secret
   via `config([...])` — if it does, the pinned value is harmless; if it reads env, align them.

2. **Add a documented `DOORDASH_*` block to `.env.example`** (empty values, with the same
   umbrella-model comment style the file already uses — one platform credential set, JWTs minted
   per request). This is launch documentation parity: DEPLOY.md Step 5 and `todo.md` §0/§3 tell
   you to set these in Cloud, but the env template never mentions them.

## Cause 3 — tests workflow: `MEDIA_DISK=public` in `.env.example`

**Failing tests:** 3 datasets of `Tests\Feature\ProductionConfigurationTest` → "it resolves the
media disk from the environment" — exactly the three datasets where `MEDIA_DISK` should be
*unset* ("Cloud: MEDIA_DISK unset…", "the misconfiguration DEPLOY.md used to prescribe",
"no configuration at all…").

The helper `resolveMediaDisk()` (tests/Feature/ProductionConfigurationTest.php:34) unsets
`$_ENV`/`$_SERVER` keys and re-requires `config/media.php`. Locally that works because the local
`.env` never sets `MEDIA_DISK` (verified — only `FILESYSTEM_DISK=local`), so the unset is a
no-op. In CI, `.env.example` sets `MEDIA_DISK=public` (line 54) and the loaded value survives the
unset — the datasets then resolve to `public` instead of the expected fallback. (Exact mechanism
of *why* the unset doesn't defeat a genuinely loaded value wasn't chased to ground; don't need to
— either fix below is deterministic regardless.)

**Fix (do both):**

1. **Comment out `MEDIA_DISK=public` in `.env.example`** (keep the explanatory comment block at
   lines 51–53). This matches the actually-working local `.env`, and makes CI mirror local. Note
   the comment currently says "Locally, set MEDIA_DISK=public" while the real local `.env`
   doesn't — reword the comment to "optionally set" while there.
2. **Harden the test helper**: in `resolveMediaDisk()`, when a value is `null`, write `''`
   instead of (or in addition to) unsetting. `config/media.php:19` is
   `env('MEDIA_DISK') ?: env('FILESYSTEM_DISK', 'local')` — `?:` treats empty string as falsy, so
   `''` exercises the same fallback and cannot be resurrected by whatever kept the loaded value
   alive. Same for the `FILESYSTEM_DISK=null` dataset (`env('FILESYSTEM_DISK', 'local')` with a
   default arg returns `''` if set-but-empty — check that dataset still expects `local`; if `''`
   breaks it, only apply the hardening to `MEDIA_DISK` and rely on fix 1 for the rest).

## Verification

1. Targeted, locally (Herd PHP path above if `php` isn't on PATH):
   `vendor/bin/pest --compact tests/Feature/ProductionConfigurationTest.php tests/Feature/Storefront/DoorDashDeliveryMoneyTest.php tests/Feature/Delivery/DoorDashProviderTest.php tests/Feature/Delivery/DoorDashWebhookTest.php`
2. Simulate the CI env for the two previously-failing files: run them once with the new
   `.env.example` values active (e.g. `MEDIA_DISK= DOORDASH_DEVELOPER_ID= vendor/bin/pest …` or
   temporarily point at a copied env) — the point is to prove the fix works *without* the
   developer `.env`'s real creds, not just with them.
3. Full local gate: `composer run ci:check` (note: its test step runs `php artisan test`, which
   needs memory; if it OOMs run pest directly with `-d memory_limit=2G`).
4. `vendor/bin/pint --dirty --format agent` if any PHP file changed.
5. Push and watch both workflows: `gh run list --branch dev --limit 4` / `gh run watch`.
   **Acceptance: both `tests` and `linter` green on `dev`, local suite still fully green.**

## Constraints

- Do not commit or push autonomously — stage the changes and hand Taylor the `git add`/`git
  commit` commands (standing instruction for this project).
- Don't touch the workflow files' check-variant commands (`lint.yml` header comment explains why
  the fixer variants were the original disease).
- `.env` (local) holds real sandbox creds — never print or copy its values into the chat/plan.
- When done, tick the CI item in `todo.md` §8 (added 2026-07-31, describes these same causes) and
  delete this file if the repo shouldn't keep one-shot plans around.
