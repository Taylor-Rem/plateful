# Task: Set up Google Places API + build a Utah County restaurant lead list

Help me enable the Google Places API and then build a lead-generation script that
finds independent restaurants in Utah County with **no website or a weak/broken
one** — the best targets for Plateful sales outreach (they're likely paying ~30%
to DoorDash with nothing of their own; Plateful is 1%).

## Context (Plateful)

- Plateful is a multi-tenant restaurant online-ordering SaaS, live at
  `https://plateful.fyi`, Laravel 13 / Vue. See `todo.md` for status.
- A **Google Cloud project already exists**: name `plateful`, id
  `plateful-497622`. Google OAuth login is already set up on it. We're now adding
  the **Maps/Places** side, which is separate from OAuth.
- Local `.env` is gitignored. An existing read-only ops script,
  `scripts/cloud-check.php`, is the pattern to follow (reads a key from `.env`,
  prints no secrets).

## ⚠️ Environment constraint — read this first

This session's sandbox has **no outbound network access**, so it **cannot call
Google APIs directly** (curl/fetch to googleapis.com will fail). Do NOT waste
time trying. The working pattern (same as `scripts/cloud-check.php`) is:

1. The session **writes a local script**; the **user runs it on their Mac**
   (which has network + PHP via Herd).
2. The script writes its output (a CSV) into the repo folder, which the session
   **can read back** to review/rank results.

So "giving you access to the API" = the user puts the API key in local `.env`;
the local script uses it. The session never needs to hit Google itself.

## Part 1 — Console setup (user does these; guide me step by step)

In the `plateful-497622` project:

1. **Enable billing** on the project — Maps Platform requires a billing account
   with a card. *(The user must do this; the assistant cannot enter payment
   details.)* There's a recurring free monthly Maps credit + a $300 free-trial
   credit that will easily cover lead-gen volume.
2. **Enable the "Places API (New)"** — APIs & Services → Library → search
   "Places API (New)" → Enable. (Use the *New* one, not the legacy Places API.)
3. **Create an API key** — APIs & Services → Credentials → Create credentials →
   API key.
4. **Restrict the key** — API restrictions → restrict to "Places API (New)"
   (add Geocoding API too if we end up needing lat/lng). Application
   restrictions: this key is used server-side from the user's Mac (dynamic IP),
   so either leave application restriction as "None" while testing, or restrict
   to the user's current IP. Note the trade-off (none = works anywhere but must
   be kept secret; IP = safer but breaks if their IP changes).
5. **Store the key** in local `.env` as `GOOGLE_MAPS_API_KEY=...` (gitignored),
   and add an empty `GOOGLE_MAPS_API_KEY=` placeholder to `.env.example`. The
   user pastes the key into `.env` directly — not into chat.

## Part 2 — Build the lead-gen script (assistant builds)

Write `scripts/plateful-leads.php` (PHP to match `cloud-check.php`; Python is
fine if preferred). It should:

1. Read `GOOGLE_MAPS_API_KEY` from `.env` (never echo it).
2. Query the **Places API (New) Text Search**:
   `POST https://places.googleapis.com/v1/places:searchText`
   with header `X-Goog-Api-Key: <key>` and a `X-Goog-FieldMask` including at
   least: `places.displayName, places.websiteUri, places.nationalPhoneNumber,
   places.formattedAddress, places.rating, places.userRatingCount,
   places.businessStatus, places.primaryType, places.googleMapsUri,
   nextPageToken`.
   - Search across Utah County towns (default set: American Fork, Lehi, Pleasant
     Grove, Orem, Provo, Spanish Fork, Springville, Lindon, Saratoga Springs,
     Payson) — e.g. text queries like "restaurants in American Fork UT". Confirm
     the town list / radius with the user before a big run.
   - Follow `nextPageToken` to page through results.
   - Dedupe by place id / name+address.
3. **Flag lead quality** for each restaurant:
   - `no_website` — no `websiteUri` at all (strongest lead).
   - `weak_website` — `websiteUri` points at a social/aggregator domain
     (facebook.com, instagram.com, doordash.com, ubereats.com, grubhub.com,
     yelp.com, linktr.ee, etc.) rather than a real site.
   - `broken_website` — optional: the script does a quick HTTP GET on the
     `websiteUri` (from the user's machine) and flags non-200 / timeout / parked
     pages.
   - Everything else = `has_website` (lower priority).
4. **Output `scripts/leads.csv`** with columns: name, address, phone, website
   (or "NONE"), rating, review_count, google_maps_url, lead_flag. Sort so
   `no_website` / `weak_website` / `broken_website` come first.
5. Handle billing/quota/permission errors with clear messages (e.g. "billing not
   enabled", "Places API (New) not enabled", "key restricted").

After the user runs it, read `scripts/leads.csv` back and present a ranked
shortlist of the best outreach targets.

## Secrets & git

- The API key lives only in local `.env` (gitignored) — never in the repo, never
  in chat.
- `scripts/plateful-leads.php` contains no secrets and can be committed.
- Gitignore the output data: add `scripts/leads.csv` (or `*.csv` under scripts)
  to `.gitignore` — it's lead data, not code.

## Verify

- Confirm the key works with a tiny first query (one town, one page) before a
  full run, so we catch billing/enable/restriction errors cheaply.
- Sanity-check the CSV: counts per lead_flag, no obviously wrong entries, no
  secret values in output.

## Done when

Places API (New) is enabled and billed, the key is in `.env`, `plateful-leads.php`
runs locally and produces a ranked `leads.csv`, and the assistant has reviewed it
and surfaced the top no-website / weak-website targets in Utah County.
