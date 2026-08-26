# Multi-location (chain) restaurants — plan

_Drafted 2026-08-18 after evaluating whether Plateful could serve a
multi-location client (prompted by small local chains like Rancherito's showing
up in the sales data). Status: **build-on-demand** — nothing here should be
built before a real multi-location prospect exists. The realistic near-term
case is a 2–3 location family operation, and today's product can already be
sold to that owner honestly with the workarounds below._

## What already works (verified 2026-08-18)

The tenancy model handles multiple locations under one owner today:

- Each location = one `Restaurant` tenant: own storefront/subdomain (or custom
  domain), own menu, hours, kitchen flow, and **own Stripe connected account**
  (per-location settlement — arguably what a family chain wants for its books).
- Admin access is a `users` ↔ `restaurants` many-to-many pivot, so one owner
  attaches to all locations under a single login; the admin home lists every
  restaurant they manage and jumps between per-location dashboards (tested
  behavior). Staff can be scoped per location via the same pivot + roles.
- Onboarding N locations = running white-glove setup N times; the menu import
  makes each one cheap.

**Sellable today:** "each location gets its own storefront; you manage them all
from one login." Don't oversell beyond that sentence.

## The four gaps a chain would actually feel

1. **No umbrella storefront / location picker.** There is no
   "chain.com → choose your location" page; each location is its own site.
   Workaround: custom domain per location, or a small static landing page
   linking them. Likely the cheapest gap to close when needed (a tenant-group
   concept + one picker page).
2. **No menu sync.** `menu_categories`, `menu_items`, and `item_templates` are
   all scoped by `restaurant_id` (verified). A price change at five locations
   is five edits. Close-when-needed shape: copy-menu-to-location as a v1
   (one-shot clone), true sync (shared master + per-location overrides for
   price/availability) only if a real client demands it — sync is where the
   complexity lives (overrides, drift, POS mapping per location).
3. **Loyalty is per-location.** `loyalty_points` is keyed
   (user_id, restaurant_id): points earned at location A can't be spent at B.
   Cross-location loyalty needs a group-level balance concept — design it
   together with the §10 redemption decision (todo.md), not before.
4. **No roll-up reporting.** Dashboards are per-restaurant; a chain owner has
   no consolidated orders/revenue view. Close-when-needed shape: an
   owner-level summary page aggregating across their pivot restaurants —
   read-only queries, no schema change.

If a real prospect appears, **menu sync (as clone-v1) and the location picker**
are the two to build first; cross-location loyalty and roll-up reporting can
follow the signed deal.

## Pricing decision to make BEFORE the first multi-location conversation

The $399/month commission cap (config `commission_monthly_cap_cents`) is
**per restaurant row**, i.e. per location. A 5-location chain caps at
$1,995/month. Options when it comes up: leave as-is (defensible: each location
gets its own storefront and support), or offer a group cap as a negotiated
concession. Decide deliberately — the cap is now advertised pricing
(todo.md §1 guardrails: don't lower the default casually).

## Data-quality note (sales side, not product)

Chain exclusion in `wasatch.db` is by name frequency (3+ locations *within the
dataset*), so chains with 1–2 captured locations slip through (observed:
Bonchon Orem in a lighthouse shortlist). When the DB gets its next accuracy
pass, consider a manual chain flag or a known-franchise name list.
