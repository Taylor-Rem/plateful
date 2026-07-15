# Deploying Plateful to Laravel Cloud

This guide walks through a fresh deploy of Plateful to Laravel Cloud (Starter tier) with Resend for transactional email.

Plateful is a multi-tenant SaaS:
- **Platform admin** lives on `admin.<primary-domain>`
- **Each restaurant** lives on `<subdomain>.<primary-domain>` (and optionally a custom domain)

Laravel Cloud handles SSL, wildcard subdomains, the database, object storage, scheduler, and queue runner.

---

## Prerequisites

- GitHub repo for this project pushed to a remote you control.
- Accounts: **Laravel Cloud** (`https://cloud.laravel.com`) and **Resend** (`https://resend.com`).
- A domain you want to use eventually (optional — Cloud gives you `your-app.laravel.cloud` for free).

---

## Step 1: Sign up & install CLIs

1. Create a Laravel Cloud account and connect your GitHub.
2. (Optional but recommended) Install the Laravel Cloud CLI: `composer global require laravel/cloud-cli` — useful for `cloud ssh`, `cloud env:pull`, etc.
3. Create a Resend account, confirm your email, and (later) verify the sending domain you want to use for `MAIL_FROM_ADDRESS`.

---

## Step 2: Configure Resend

1. In the Resend dashboard, create an **API key** with "Sending access" → copy it. You'll paste this into Cloud as `RESEND_API_KEY` in Step 5.
2. Add and verify your sending domain (e.g. `mail.your-domain.com`). Until DNS is verified you can only send from `onboarding@resend.dev` to your own verified address — fine for the very first smoke test, not for production.

---

## Step 3: Push the repo

Make sure your local `main` is committed and pushed:

```bash
git status
git push origin main
```

`composer.lock` and `package-lock.json` must be committed (they are).

---

## Step 4: Create the Laravel Cloud project

1. In Cloud, **New Project** → connect the GitHub repo.
2. Region: pick the one closest to your users (e.g. `us-east-1`).
3. Plan: **Starter**.
4. When asked about resources to provision, accept the defaults:
   - **Postgres database** (serverless) — Cloud will auto-inject `DB_*` env vars.
   - **Object storage** bucket — Cloud will auto-inject `AWS_*` env vars (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_URL`).
5. **Build command:** `composer install --no-dev --optimize-autoloader && npm ci && npm run build`
6. **Deploy command (post-deploy hook):** `php artisan migrate --force && php artisan storage:link && php artisan config:cache && php artisan route:cache && php artisan view:cache`
   - **Important:** never use `migrate:fresh` or `db:seed` in production. Plateful has real photo data; only forward-only migrations are safe.
7. **Scheduler:** enable it. Cloud will run `schedule:run` every minute. Currently nothing is scheduled, but enabling it now means cron jobs added later "just work."
8. **Queue worker: required.** On Starter the queue runs against the database — enable Cloud's queue
   worker. `QUEUE_CONNECTION=database` does **not** mean jobs run inline; with no worker they sit in
   the `jobs` table forever. Order placement queues confirmation mail, `PushOrderToPos`,
   `DispatchDeliveryForOrder`, and `ExpireAuthorizedDelivery` (which of them fire depends on the
   order type — a courier delivery holds its POS push until the courier is confirmed), so without a
   worker: tickets never reach the kitchen, deliveries never dispatch, and — because courier-network
   orders authorize rather than capture — **authorization holds sit on customer cards with nothing
   scheduled to release them**. This is the one operational dependency with no in-code backstop.

---

## Step 5: Set environment variables in Cloud

In the project **Environment** tab, set the following. Anything marked *auto* is injected by Cloud — do **not** set it manually.

### App

| Key | Value |
|---|---|
| `APP_NAME` | `Plateful` |
| `APP_ENV` | `production` |
| `APP_KEY` | Click "Generate" in Cloud (or `php artisan key:generate --show` locally) |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-app.laravel.cloud` (whatever Cloud assigns you — see Step 6) |
| `APP_LOCALE` | `en` |
| `APP_FALLBACK_LOCALE` | `en` |
| `BCRYPT_ROUNDS` | `12` |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `info` |

### Database (all auto-injected — verify they appear)

| Key | Source |
|---|---|
| `DB_CONNECTION` | set to `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | *auto* (Cloud Postgres) |

### Sessions / cache / queue (Starter, no Redis)

| Key | Value |
|---|---|
| `SESSION_DRIVER` | `database` |
| `SESSION_LIFETIME` | `120` |
| `SESSION_DOMAIN` | `null` (leave unset — see "Multi-tenant cookies" in Troubleshooting) |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `BROADCAST_CONNECTION` | `log` |

On Growth+ with Redis/KV available, switch all three to `redis`.

### Mail (Resend)

| Key | Value |
|---|---|
| `MAIL_MAILER` | `resend` |
| `MAIL_FROM_ADDRESS` | `hello@your-verified-domain.com` |
| `MAIL_FROM_NAME` | `Plateful` |
| `RESEND_API_KEY` | the key from Step 2 |

### Stripe (payments — nothing works without these)

| Key | Value |
|---|---|
| `STRIPE_KEY` | live **publishable** key (`pk_live_…`) |
| `STRIPE_SECRET` | live **secret** key (`sk_live_…`) |
| `STRIPE_WEBHOOK_SECRET` | the `whsec_…` for the **live Connect webhook** pointed at `https://admin.<primary>/stripe/webhook` (create it in the Stripe dashboard; it must listen to *connected account* events — direct charges fire `checkout.session.completed` on the connected account) |
| `STRIPE_CONNECT_COUNTRY` | `US` |

`scripts/cloud-check.php` reports these as LIVE/TEST by prefix — run it after setting them.

### POS — Square & Clover (⚠ both default to `sandbox`)

`config/services.php` falls back to `sandbox` for both providers and the API/OAuth **hosts key off
this value** — if production doesn't set these explicitly, every OAuth connect and every ticket
push silently goes to the sandbox hosts and real registers never see an order.

| Key | Value |
|---|---|
| `SQUARE_ENVIRONMENT` | `production` |
| `SQUARE_APPLICATION_ID` / `SQUARE_APPLICATION_SECRET` | from the Square developer dashboard (production credentials, not sandbox) |
| `SQUARE_REDIRECT_URI` | `https://admin.<primary>/pos/square/callback` (must match the URI registered on the Square app) |
| `CLOVER_ENVIRONMENT` | `production` |
| `CLOVER_APP_ID` / `CLOVER_APP_SECRET` | from the Clover developer dashboard |
| `CLOVER_REDIRECT_URI` | `https://admin.<primary>/pos/clover/callback` (must match the Clover app registration) |

### Delivery & address lookup

| Key | Value |
|---|---|
| `GOOGLE_MAPS_API_KEY` | server-side Places API key (IP-restricted to the production server — it is proxied through the backend, never sent to browsers) |
| `UBER_DIRECT_SANDBOX_*` | leave **unset** in production — Uber Direct credentials are per-restaurant and live encrypted in `delivery_integrations`; the sandbox vars exist only for local dev and the opt-in live test |

### Google login (optional but wired)

| Key | Value |
|---|---|
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | from the Google Cloud OAuth client |
| `GOOGLE_REDIRECT_URI` | `https://<primary>/auth/google/callback` (root host — Google forbids wildcard subdomains) |

### AI menu import

| Key | Value |
|---|---|
| `CLAUDE_API_KEY` | Anthropic API key — without it the menu photo/PDF import (`ExtractMenuJob`) fails and the "free setup" onboarding flow is dead |

### Error monitoring

| Key | Value |
|---|---|
| `SENTRY_LARAVEL_DSN` | project DSN (see "Consider adding later" below for details) |
| `SENTRY_TRACES_SAMPLE_RATE` | `0.1` |

### Storage (restaurant assets → Cloud object storage)

| Key | Value |
|---|---|
| `FILESYSTEM_DISK` | `s3` |
| `MEDIA_DISK` | leave **unset** |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_URL` | *auto* (Cloud object storage) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` (or whatever Cloud's docs recommend) |

Restaurant media (logos, menu-item images, hero/about images, gallery photos) resolves through
`config/media.php`: `MEDIA_DISK` if set, otherwise `FILESYSTEM_DISK`. Leaving `MEDIA_DISK` unset in
Cloud makes media follow the default disk onto the injected bucket, which is what you want — set it
only to override media independently of the app default (locally it's `public`, so `Storage::url()`
resolves via `storage:link`).

**`FILESYSTEM_DISK` is the setting that matters.** Leave it at `local` and every uploaded logo and
menu photo writes to the container's ephemeral disk and disappears on the next deploy.

### Platform / tenancy

| Key | Value |
|---|---|
| `PLATFORM_PRIMARY_DOMAIN` | the bare host Cloud assigned, e.g. `your-app.laravel.cloud` |
| `PLATFORM_ADMIN_SUBDOMAIN` | `admin` |

---

## Step 6: First deploy

1. Trigger the first deploy (push to `main` or click "Deploy" in Cloud).
2. The build command installs PHP deps without dev, then builds the Vite bundle.
3. The post-deploy hook runs migrations and caches config/routes/views.
4. When it goes green, note the URL Cloud assigned (e.g. `https://app-abc123.laravel.cloud`). Update `APP_URL` and `PLATFORM_PRIMARY_DOMAIN` if they don't match, and redeploy.

---

## Step 7: Configure wildcard subdomain routing

Plateful needs Cloud to route **both** `admin.<primary>` and **all** `*.<primary>` subdomains to the same app:

1. In Cloud → **Domains**, add:
   - `<primary>` (e.g. `your-app.laravel.cloud`)
   - `*.<primary>` (wildcard)
2. Verify both resolve and serve the app over HTTPS.

If you're using Cloud's default `*.laravel.cloud` subdomain, wildcard subdomains under your project hostname should work automatically — confirm in their dashboard.

---

## Step 8: Create the super admin

Open Cloud's web console (or `cloud ssh` if available) and run:

```bash
php artisan plateful:create-super-admin --email=you@example.com --name="Your Name"
```

You'll be prompted for a password (min 12 chars). The command is idempotent: it errors clearly if the email already exists. It creates a user with `is_super_admin=true` and a verified email (there is no `role` column — admin access is per-restaurant via the `restaurant_user` pivot; `is_super_admin` bypasses it).

---

## Step 9: Smoke-test the deployment

1. Visit `https://admin.<primary>` → you should see the admin login.
2. Log in with the super admin you just created.
3. Visit `https://<some-existing-restaurant-subdomain>.<primary>` → storefront should render with theming and images served from Cloud object storage.
4. Trigger a transactional email (e.g. password reset) → check the Resend dashboard "Emails" tab to confirm delivery.
5. Place a test order on the storefront → confirm it lands in the admin orders list.

---

## Step 10: Point a real domain at it (when ready)

1. In Cloud → **Domains**, add your apex (e.g. `plateful.app`) and `*.plateful.app`.
2. Add the CNAME / A records Cloud shows you at your DNS provider.
3. Wait for cert issuance (Cloud handles Let's Encrypt automatically).
4. Update env vars:
   - `APP_URL=https://plateful.app`
   - `PLATFORM_PRIMARY_DOMAIN=plateful.app`
5. Redeploy.

---

## Troubleshooting

### Multi-tenant session cookies
We deliberately leave `SESSION_DOMAIN=null` so each tenant subdomain (`admin.X`, `marcos.X`, `bobs.X`) gets its own cookie scope. **Do not** set it to `.<primary>` — that would let a customer logged in on `marcos.X` see their session on `bobs.X`.

### "Mixed content" warnings or http URLs in pages
`AppServiceProvider` calls `URL::forceScheme('https')` in production, and the app trusts all proxies via `bootstrap/app.php`. If you still see `http://` URLs, confirm `APP_ENV=production` and `APP_URL` starts with `https://`, then `php artisan config:clear`.

### Image URLs broken
Check `config('media.disk')` in Cloud's `php artisan tinker` — it should report `s3`, not `local`. If it says `local`, `FILESYSTEM_DISK` is wrong (see Storage above). Then confirm `Storage::disk(config('media.disk'))->url('some/path.jpg')` returns an HTTPS URL pointing at the Cloud object storage bucket (or `AWS_URL` if you set a custom CDN). The accessors `MenuItem::image_url` and `Restaurant::logo_url` resolve through `config('media.disk')` and don't hardcode anything.

### "Unable to locate file in Vite manifest"
The build step didn't run, or `public/build` wasn't deployed. Re-check the build command in Cloud and redeploy.

### Migrations failing on first deploy
Cloud runs `migrate --force`. If a column already exists from a partial deploy, fix it via a fresh migration — **never** `migrate:fresh` (would wipe restaurant photos and orders).

### Resend rejecting mail
Until your sending domain is verified, you can only send to addresses in your Resend account. Verify DNS records in Resend, then retry.

### Logs
`LOG_CHANNEL=stderr` sends logs to stdout/stderr which Cloud captures in the **Logs** tab.

---

## Live environment & ops reference (Plateful production)

The concrete values for the running deployment (the generic runbook above uses
placeholders):

- **Source:** GitHub, branch `main` (repo `Taylor-Rem/plateful`). Laravel Cloud
  auto-deploys on push to `main`.
- **Host:** Laravel Cloud, project `plateful`, environment `main`, live at
  <https://plateful.fyi>. Dashboard: <https://cloud.laravel.com/taylor-remund/plateful/main>.
- **DNS:** Porkbun (Cloudflare backend) for `plateful.fyi`.
- **Ops without the dashboard:** Laravel Cloud REST API / CLI. A token lives in
  local `.env` as `LARAVEL_CLOUD_TOKEN` (gitignored, never in the repo or Cloud).
  Read-only readiness check: `php scripts/cloud-check.php`.
- **Inbox mail:** Zoho (`founder@plateful.fyi` + `orders@`/`service@`/`support@`
  aliases). Outbound app mail goes through Resend on a **separate sending
  subdomain** so the two SPF records don't collide (see gotchas).

### Known constraints / gotchas

- **Google OAuth:** only `https://plateful.fyi/auth/google/callback` is registered;
  Google allows no wildcard subdomains and rejects `.test`, so local Google login
  is limited. The callback must stay on the root/platform host.
- **SPF:** a domain may have only one SPF record. Zoho (inbox) and Resend
  (outbound app mail) must not collide — keep Resend on a sending subdomain
  (e.g. `send.plateful.fyi`).
- **Vite build:** needs Node 20.19+ or the build fails.
- **Cloud baseline vars:** Laravel Cloud auto-injects framework vars (`APP_ENV`,
  etc.) that don't appear in the API's editable env-var list, so
  `scripts/cloud-check.php` may report them "MISSING" even though they're set —
  confirm in the dashboard if unsure.
- **DNS lookups:** public-resolver queries against Porkbun/Cloudflare can return
  inconsistent results across nodes. Trust the Porkbun records table or a
  real-world send/receive test over a single one-off lookup.

## Recurring release checklist (every deploy)

**Before push**

- [ ] Tests green: `php artisan test --compact`
- [ ] PHP formatting: `vendor/bin/pint --dirty --format agent`
- [ ] JS lint/format: `npm run lint && npm run format`
- [ ] If routes changed: `php artisan wayfinder:generate`
- [ ] Any new env vars added to Laravel Cloud **and** `.env.example`

**Deploy**

- [ ] Merge / push to `main` → Cloud auto-deploys (or trigger via CLI/API)
- [ ] Watch the deploy logs for build or migration failures

**After deploy**

- [ ] Confirm migrations ran
- [ ] Smoke test: homepage, a restaurant storefront, an order, and login
- [ ] `php scripts/cloud-check.php` clean
- [ ] Spot-check production logs for new errors

> **Current launch status and blockers** (Stripe go-live, Resend wiring, etc.)
> live in [`todo.md`](todo.md) §0 — that's the single source of "what's left
> before selling," so it isn't duplicated here.

## Consider adding later

- **Error monitoring (Sentry)**: `sentry/sentry-laravel` is installed and wired into `bootstrap/app.php`, and stays a no-op until a DSN is present. To turn it on in production, set two env vars in Cloud's **Environment** tab:
  - `SENTRY_LARAVEL_DSN` — the DSN from your Sentry project dashboard (**Settings → Projects → [project] → Client Keys (DSN)**). Leave it blank/unset locally and in tests so no events are sent.
  - `SENTRY_TRACES_SAMPLE_RATE` — `0.1` to start (10% of requests traced); raise or lower as needed.

  Logging is unaffected — `LOG_CHANNEL=stderr` keeps working; Sentry is additive. After setting the vars, redeploy and confirm the next unhandled exception shows up in Sentry.
- **Custom queue worker**: as background work grows, move from `database` queue + sync to a dedicated Cloud queue worker on Growth.
- **Scheduled cleanup**: nothing prunes `pending_checkouts` (each row is a full order snapshot; abandoned checkouts accumulate forever) or expired `delivery_quotes`. Add prune jobs once volume makes it matter — the scheduler is already enabled (Step 4).
- **Backups**: Cloud Postgres is managed but verify the backup policy on Starter and set up an off-Cloud DB snapshot routine if needed.
