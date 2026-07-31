# Plateful

Multi-tenant online ordering for restaurants. Each restaurant gets its own branded
storefront on a subdomain; customers order and pay there, and the restaurant runs
operations (menu, orders, kitchen, payouts, integrations) from an admin console.

**Pricing model:** 4% flat per order — 4% of the post-redemption food subtotal, i.e. what
the customer actually pays for food. Tax, tip, and the delivery fee are excluded, always.
Charged via a Stripe Connect application fee on top of the restaurant's own Stripe
processing. No subscriptions, no tiers — but the commission is **capped at $249 per
restaurant per calendar month** (`config/platform.php`, per-restaurant override), and super
admins can override the 4% rate per restaurant. The restaurant is the merchant of record.
(Loyalty redemption isn't built yet, so today the post-redemption subtotal is simply the
food subtotal.)

## What's in the box

| Subsystem | Where | Notes |
|---|---|---|
| Storefronts | `{subdomain}.plateful.test` | Branded menu, cart, Stripe-hosted checkout |
| Admin console | `admin.plateful.test` | Super admin + per-restaurant tenant admin at `/{subdomain}/…` |
| Payments | `app/Services/Stripe` | Stripe Connect Express, direct charges + application fee |
| POS injection | `app/Services/Pos` | Square and Clover — orders push into the restaurant's POS |
| Delivery | `app/Services/Delivery` | DoorDash Drive (launch provider, centrally billed), self-delivery; Uber Direct adapter dormant |
| AI menu import | `app/Services/MenuExtractionService.php` | Claude extracts a structured menu (incl. option sets) from a PDF/photo; re-import anytime from the admin Menu page |
| Auth | Fortify + Socialite | Email/password + Google OAuth; TOTP two-factor (required for super admins) |
| Revenue split | `app/Services/RevenueSplitResolver.php` | Founder/operator/recruiter attribution ledger + monthly earnings |

Deeper design docs live in `docs/` (POS strategy, DoorDash Drive plan, the dormant Uber
Direct plan, admin overhaul, user management). Deployment to Laravel Cloud is covered in
`DEPLOY.md`.

## Stack

- Laravel 13 / PHP 8.4, PostgreSQL, database queue
- Inertia v3 + Vue 3 + Tailwind CSS 4 (Vite, Wayfinder for typed routes)
- Stripe Connect (Express accounts, direct charges, Stripe-hosted Checkout)
- Pest 4 for tests (incl. real-browser tests via Playwright), Pint/Prettier/ESLint
- Served locally by [Laravel Herd](https://herd.laravel.com)

## Local development

| Host | What it is |
|---|---|
| `plateful.test` | Diner-facing homepage + `/for-restaurants` owner signup |
| `admin.plateful.test` | Admin console |
| `{subdomain}.plateful.test` | A restaurant's storefront (e.g. `marcos.plateful.test`) |

Use `http://` locally.

```bash
composer run setup   # install, .env, key, migrate, npm install + build
composer run dev     # serve + queue + logs + vite, concurrently
```

Vite requires Node 20.19+. On Node 18 the build fails — new top-level Vue pages then
need a stub entry in `public/build/manifest.json` (gitignored) for headless render tests
to pass.

Nearly everything asynchronous rides the database queue (`QUEUE_CONNECTION=database`).
Without a running worker, orders never push to the POS, deliveries never dispatch, mail
never sends, and card holds never release. `composer run dev` starts one for you.

### Stripe (test mode)

1. Add test keys to `.env`: `STRIPE_KEY` (publishable), `STRIPE_SECRET`, and
   `STRIPE_CONNECT_COUNTRY=US`.
2. Forward Connect webhooks (direct charges fire events on the connected account):

   ```bash
   stripe listen --forward-connect-to http://admin.plateful.test/stripe/webhook
   ```

3. Put the printed `whsec_…` in `STRIPE_WEBHOOK_SECRET`, then `php artisan config:clear`.

Test card: `4242 4242 4242 4242`, any future expiry/CVC/ZIP.

### Other integrations (optional locally)

Each integration is off until its keys exist in `.env` — see `config/services.php`:

- **Square / Clover** — `SQUARE_*` / `CLOVER_*` OAuth app credentials (sandbox).
- **DoorDash Drive** — `DOORDASH_*` (umbrella model: one platform credential set, JWTs
  minted per request; restaurants store nothing).
- **AI menu import** — `CLAUDE_API_KEY` (extraction costs ~$0.11/menu).
- **Google** — `GOOGLE_CLIENT_ID`/`SECRET` for social login, `GOOGLE_MAPS_API_KEY` for
  address handling.

## How an order flows

1. Checkout is **pay-first**: the prospective order is snapshotted to
   `pending_checkouts`, and a Checkout Session is created on the restaurant's connected
   account with a 4% `application_fee_amount` (plus courier cost + tip passthrough for
   centrally-billed DoorDash deliveries).
2. Pickup and self-delivery capture immediately; courier deliveries use
   `capture_method: manual` — the card is only *held* until a courier is confirmed.
3. The `orders` row materializes idempotently from both the webhook and the success-URL
   return, then queued jobs fan out: POS push, delivery dispatch, receipt mail.
4. `DeliverySettlement` captures the hold when the courier confirms, or voids it if no
   courier materializes (a deadline job polls the provider, so a missed webhook costs
   latency, not correctness). Cancelling a captured order refunds and reverses the fee;
   cancelling an authorized one just voids the hold.

A restaurant can't go live until Stripe Connect onboarding is complete
(`stripe_account_status = enabled`).

## Account model

Platform-wide accounts (Shopify pattern): one `users` row per email, globally. A user's
relationship to a restaurant lives in pivots — `restaurant_user` (Admin/Staff roles,
gates the admin console) and `restaurant_customer` (customer history/counters). Super
admins have `is_super_admin` and are hard-required to enroll in two-factor. The same
account works at every storefront. Users are soft-deletable; self-service deletion is a
hard delete.

## Tests & formatting

```bash
php -dmemory_limit=512M vendor/bin/pest --compact   # tests (512M: image tests need it)
vendor/bin/pint --dirty --format agent              # PHP formatting
npm run lint && npm run format                      # JS/Vue
composer run ci:check                               # everything CI runs
```

Real-browser tests live in `tests/Browser/` (Pest v4 + Playwright). One-time setup:
`npx playwright install chromium`. They need a fresh `npm run build` to assert against
current JS. The admin host is domain-routed, so browser tests set
`Playwright::setHost('admin.plateful.test')` and use relative `visit()` URLs — absolute
URLs bypass the in-process test server and hit the live Herd site instead.

If `php` isn't on your PATH, use Herd's binary:
`"$HOME/Library/Application Support/Herd/bin/php84"`.

After adding/changing routes, run `php artisan wayfinder:generate` (TypeScript route
helpers; Vue imports from `@/actions` / `@/routes`).
