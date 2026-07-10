# Plateful

Multi-tenant online ordering for restaurants. Each restaurant gets its own branded
storefront on a subdomain; customers order and pay there, and the restaurant runs
operations (menu, orders, kitchen, payouts) from an admin console.

**Pricing model:** 4% flat per order, charged on the food subtotal only via a Stripe Connect
application fee (on top of the restaurant's own Stripe processing). No subscriptions, no tiers.
The restaurant is the merchant of record.

## Stack

- Laravel 13 / PHP 8.4, PostgreSQL
- Inertia v3 + Vue 3 + Tailwind CSS 4 (Vite)
- Stripe Connect (Express accounts, direct charges, Stripe-hosted Checkout)
- Pest for tests, Pint/Prettier/ESLint for formatting
- Served locally by [Laravel Herd](https://herd.laravel.com)

## Local hosts

| Host | What it is |
|---|---|
| `plateful.test` | Diner-facing homepage + `/for-restaurants` owner signup |
| `admin.plateful.test` | Admin console (super admin + per-restaurant tenant admin at `/{subdomain}/…`) |
| `{subdomain}.plateful.test` | A restaurant's storefront (e.g. `marcos.plateful.test`) |

Use `http://` locally.

## Setup

```bash
composer run setup   # install, .env, key, migrate, npm install + build
composer run dev     # serve + queue + logs + vite, concurrently
```

Note: Vite requires Node 20.19+. On Node 18 the build fails — new top-level Vue pages
then need a stub entry in `public/build/manifest.json` (gitignored) for headless render
tests to pass.

### Stripe (test mode)

1. Add test keys to `.env`: `STRIPE_KEY` (publishable), `STRIPE_SECRET`, and
   `STRIPE_CONNECT_COUNTRY=US`.
2. Forward Connect webhooks (direct charges fire events on the connected account):

   ```bash
   stripe listen --forward-connect-to http://admin.plateful.test/stripe/webhook
   ```

3. Put the printed `whsec_…` in `STRIPE_WEBHOOK_SECRET`, then `php artisan config:clear`.

Test card: `4242 4242 4242 4242`, any future expiry/CVC/ZIP.

## How payments flow

1. Storefront checkout snapshots the prospective order to `pending_checkouts`
   (pay-first: no `orders` row until payment succeeds).
2. A Checkout Session is created **on the restaurant's connected account** with
   `application_fee_amount` = 4% of the food subtotal (not tax/tip/delivery).
3. The order materializes idempotently from both the `checkout.session.completed`
   webhook and the success-URL return (unique `orders.stripe_checkout_session_id`).
4. Cancelling a paid order issues a full refund and reverses the application fee.

A restaurant can't go live until Stripe Connect onboarding is complete
(`stripe_account_status = enabled`).

## Account model

Platform-wide accounts (Shopify pattern): one `users` row per email, globally. A user's
relationship to a restaurant lives in pivots — `restaurant_user` (Admin/Staff roles,
gates the admin console) and `restaurant_customer` (customer history/counters). Super
admins have `is_super_admin`. The same account works at every storefront.

## Tests & formatting

```bash
php -dmemory_limit=512M vendor/bin/pest --compact   # tests (512M: image tests need it)
vendor/bin/pint --dirty --format agent              # PHP formatting
npm run lint && npm run format                      # JS/Vue
```

If `php` isn't on your PATH, use Herd's binary:
`"$HOME/Library/Application Support/Herd/bin/php84"`.

After adding/changing routes, run `php artisan wayfinder:generate` (TypeScript route
helpers; Vue imports from `@/actions` / `@/routes`).
