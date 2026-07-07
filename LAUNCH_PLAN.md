# Plateful — Launch & Deployment Plan

Living plan and checklist for taking Plateful from "live but in test mode" to
"selling to real restaurants." Meant to be picked up by any future session for
continuity.

This complements **DEPLOY.md** (which is the from-scratch "stand up a fresh
Laravel Cloud environment" runbook). This doc tracks **current status**, the
**launch blockers**, and the **recurring release checklist** — it does not
re-explain initial setup.

_Last updated: 2026-07-07_

---

## How deployment works

- **Source:** GitHub, branch `main` (repo `Taylor-Rem/plateful`). Laravel Cloud
  auto-deploys on push to `main`.
- **Host:** Laravel Cloud, project `plateful`, environment `main`, live at
  <https://plateful.fyi>. Dashboard:
  <https://cloud.laravel.com/taylor-remund/plateful/main>.
- **DNS:** Porkbun (Cloudflare backend) for `plateful.fyi`.
- **Ops without the dashboard:** Laravel Cloud REST API / CLI. A token lives in
  local `.env` as `LARAVEL_CLOUD_TOKEN` (gitignored). Read-only readiness check:
  `php scripts/cloud-check.php`.
- **Build note:** Vite requires Node 20.19+.

---

## Current status (2026-07-07)

**Done**

- [x] App deployed and live on Laravel Cloud (`plateful.fyi`)
- [x] Stripe Connect integration (currently **test mode**)
- [x] Legal pages (Terms of Service, Privacy Policy) live and linked in footer
- [x] Zoho email inbox: `founder@plateful.fyi` + `orders@`/`service@`/`support@`
      aliases — sending and receiving confirmed working
- [x] Google OAuth client created and app **published to Production** (Google
      Auth Platform, External, non-sensitive scopes → no verification review).
      `GOOGLE_*` env vars set locally.

**Outstanding** (details in the checklists below)

- [ ] Stripe go-live — **LAUNCH BLOCKER**
- [ ] Resend transactional email wired up — **LAUNCH BLOCKER**
- [ ] Google auth app code (prompt: `prompts/build-google-auth.md`)
- [ ] Error monitoring / Sentry (prompt: `prompts/add-error-monitoring.md`)
- [ ] Google Maps / Places API for restaurant lead-gen (billing + key + script)

---

## Launch blockers (clear these before real orders / selling)

### 1. Stripe live mode

- [ ] Swap `STRIPE_KEY` / `STRIPE_SECRET` to **live** keys (`pk_live_` / `sk_live_`) in Cloud
- [ ] Create a **live** webhook endpoint in Stripe → set live `STRIPE_WEBHOOK_SECRET`
- [ ] Each restaurant (starting with Marcos) completes **live** Stripe Connect onboarding
      (test-mode onboarding does not carry over)
- [ ] Place one real end-to-end order; confirm the 1% application fee and payout land correctly

### 2. Transactional email (Resend)

- [ ] Add a **sending subdomain** (e.g. `send.plateful.fyi`) as a domain in Resend
- [ ] Add the SPF/DKIM records Resend generates into Porkbun — keep them separate
      from Zoho's records (one SPF record per domain; the subdomain avoids a collision)
- [ ] Set `MAIL_FROM_ADDRESS` (e.g. `orders@plateful.fyi`), `MAIL_FROM_NAME=Plateful`,
      and `RESEND_API_KEY` in Cloud
- [ ] Trigger a password reset on the live site; confirm delivery in the Resend dashboard

### 3. Final verification

- [ ] `php scripts/cloud-check.php` shows Stripe **LIVE**, mail configured, and no recent errors

---

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
- [ ] Smoke test: homepage, a restaurant storefront, a test-mode order, and login
- [ ] `php scripts/cloud-check.php` clean
- [ ] Spot-check production logs for new errors

---

## Environment variables (Laravel Cloud)

Never commit real values — placeholders live in `.env.example`.

| Group | Keys |
|---|---|
| App | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://plateful.fyi`, `APP_KEY`, `LOG_CHANNEL=stderr` |
| Database | Bound / auto-provided by Cloud |
| Stripe | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CONNECT_COUNTRY=US` |
| Mail | `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME=Plateful`, `RESEND_API_KEY` |
| Storage | `FILESYSTEM_DISK`, `FILESYSTEM_RESTAURANT_ASSETS_DRIVER=s3`, `AWS_*` (auto from Cloud object storage) |
| Google auth | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=https://plateful.fyi/auth/google/callback` |
| Monitoring | `SENTRY_LARAVEL_DSN`, `SENTRY_TRACES_SAMPLE_RATE` (once Sentry is added) |
| Ops (local only) | `LARAVEL_CLOUD_TOKEN` in local `.env` — never in the repo or Cloud |

---

## Known constraints / gotchas

- **Google OAuth:** only `https://plateful.fyi/auth/google/callback` is registered;
  Google allows no wildcard subdomains and rejects `.test`, so local Google login
  is limited. The callback must stay on the root/platform host.
- **SPF:** a domain may have only one SPF record. Zoho (inbox) and Resend
  (outbound app mail) must not collide — put Resend on a sending subdomain.
- **Vite build:** needs Node 20.19+ or the build fails.
- **Cloud baseline vars:** Laravel Cloud auto-injects framework vars (`APP_ENV`,
  etc.) that don't appear in the API's editable env-var list, so
  `scripts/cloud-check.php` may report them "MISSING" even though they're set —
  confirm in the dashboard if unsure.
- **DNS lookups:** public-resolver queries against Porkbun/Cloudflare can return
  inconsistent results across nodes. Trust the Porkbun records table or a
  real-world send/receive test over a single one-off lookup.

---

## Artifacts & references

- `DEPLOY.md` — from-scratch Laravel Cloud + Resend setup runbook
- `scripts/cloud-check.php` — read-only production readiness check
- `prompts/add-error-monitoring.md` — Claude Code prompt to add Sentry
- `prompts/build-google-auth.md` — Claude Code prompt to build Google login
- `README.md` — architecture, account model, payments flow
