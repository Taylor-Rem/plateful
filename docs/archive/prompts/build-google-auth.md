# Task: Add "Sign in with Google" (Socialite) to Plateful

Implement Google OAuth login for customers using Laravel Socialite. The Google
Cloud OAuth client is already created and the credentials are already in the
environment — you are building the app-side integration only.

## Context you can rely on

- Laravel 13 / PHP 8.4, Inertia v3 + Vue 3, PostgreSQL. Auth is handled by
  **Laravel Fortify**. Follow `CLAUDE.md` conventions throughout (run the Boost
  `search-docs` tool before coding, use `php artisan make:*`, Pint, tests).
- **Credentials already exist** in the environment (do NOT hardcode; do NOT
  commit real values). The three vars are already in local `.env` and must also
  be set in Laravel Cloud for production:
  - `GOOGLE_CLIENT_ID`
  - `GOOGLE_CLIENT_SECRET`
  - `GOOGLE_REDIRECT_URI=https://plateful.fyi/auth/google/callback`
  Add these three as empty placeholders to `.env.example`.
- The Google OAuth client is registered with **exactly one** redirect URI:
  `https://plateful.fyi/auth/google/callback`. Google does not allow wildcard
  subdomains, so the callback MUST live on the root/platform host.
- Ignore the stale `feature/google-login` branch entirely — it is ~12k lines
  behind `main` and must not be merged. Build fresh on `main`.

## Account model (important — read before coding)

Plateful uses **platform-wide accounts**: one `users` row per email, global
across all restaurants (see `README.md` "Account model"). So Google login is a
platform-level account action, not a per-tenant one.

- Match/resolve the user in this order: (1) existing `users.google_id`, then
  (2) existing `users.email` — **but only auto-link by email if Google reports
  the email as verified** (`$googleUser->user['email_verified'] === true`).
  This prevents account-takeover via an unverified Google email.
- If no user matches, create one: name + email from Google, `email_verified_at`
  set to now, a random strong password, and store `google_id` (and avatar URL if
  easy). Reuse Fortify's user creation conventions where sensible.
- Add a migration for nullable `google_id` (unique) and nullable `avatar` on
  `users`. Add them to the model's `$fillable`/casts as appropriate.

## Multi-tenant redirect handling (the tricky part)

Customers sign in from storefront subdomains (`marcos.plateful.fyi`), the root
domain, and possibly the admin host — but Google can only return to
`plateful.fyi/auth/google/callback`. So:

1. Register both routes in `routes/web.php` (the platform-host group) so the
   callback resolves on `plateful.fyi`, not behind tenant resolution that would
   reject it. Name them `auth.google.redirect` and `auth.google.callback`.
2. On redirect, capture where the user should return after login (the storefront
   URL / subdomain they came from) and carry it through the OAuth **`state`**
   parameter, signed/validated so it can't be tampered with. On callback, after
   logging the user in, redirect them back to that origin.
3. Check `SESSION_DOMAIN` in config first: if sessions are already shared across
   `*.plateful.fyi` (a leading-dot cookie domain), you may be able to stash the
   intended URL in the session instead of `state`. Use whichever is correct for
   this app's actual session config — verify, don't assume. Preserve Socialite's
   CSRF `state` protection either way (don't disable it blindly).

## Implementation checklist

1. `composer require laravel/socialite` (this is the one approved new dependency
   for this task).
2. Add a `google` block to `config/services.php` reading the three env vars.
3. Controller (match existing controller conventions/namespacing — e.g.
   `app/Http/Controllers/Auth/GoogleController.php`) with two actions:
   - `redirect()` → `Socialite::driver('google')->redirect()`, carrying the
     return destination.
   - `callback()` → resolve/create the user per the account-model rules above,
     `Auth::login($user, remember: true)`, redirect to the captured destination
     (fall back to a sensible default like the storefront home or account page).
   - Handle the user-denied / error case gracefully (redirect back to login with
     a flash message, don't 500).
4. Routes in `routes/web.php` (platform host), named as above.
5. Frontend: add a "Continue with Google" button to the customer login and
   register pages — `resources/js/pages/auth/Login.vue` and
   `resources/js/pages/auth/Register.vue` — linking to the `auth.google.redirect`
   route via the Wayfinder-generated helper. Match the existing button styling.
   Hide the button when Google isn't configured (empty client id) so local dev
   without credentials still renders cleanly.
6. Run `php artisan wayfinder:generate` after adding routes (Vue imports route
   helpers from `@/routes` / `@/actions`).

## Local dev note

Google rejects `.test` redirect URIs, so login can't complete against
`plateful.test` with the production client. Don't try to make `.test` work.
Acceptable: the button is hidden/no-op locally unless a developer wires their own
localhost client. Feature tests (below) cover the logic without hitting Google.

## Tests (required)

Add Pest feature tests that mock Socialite (do not hit Google's servers). Cover:

- `redirect()` route redirects to Google (assert redirect to accounts.google.com
  or that Socialite's redirect is invoked).
- Callback **creates a new user** from a Google account that doesn't exist yet,
  logs them in, and sets `email_verified_at` + `google_id`.
- Callback **logs in an existing user matched by email** (email verified case),
  and links `google_id` to that row.
- Callback **does not auto-link** when Google reports the email as unverified.
- Callback matches by `google_id` when present.
- Callback redirects back to the captured origin destination.

Use Socialite mocking (check `search-docs` for the current pattern, e.g.
`Socialite::shouldReceive('driver->user')->andReturn($fakeUser)`).

## Definition of done

- `composer require` added Socialite; `config/services.php` has the google block;
  migration for `google_id`/`avatar` created and reversible; controller + named
  routes on the platform host; the state/return-destination flow works across
  subdomains; login/register pages have a working, conditionally-shown Google
  button; `wayfinder:generate` run.
- All new tests pass (`php artisan test --compact`), `vendor/bin/pint --dirty
  --format agent` clean, `npm run lint && npm run format` clean.
- Summarize what changed and confirm the env vars needed in Laravel Cloud.
- Do NOT commit real credentials. Do NOT create extra documentation files.
