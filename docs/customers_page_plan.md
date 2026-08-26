# Customers page — plan

_Drafted 2026-08-18, from the DoorDash Storefront competitive analysis. Status:
**Phase 1 BUILT 2026-08-24** (including the consent-capture amendment) — built
ahead of the lighthouse trigger by explicit decision. **Phase 2 (regulars
stats) BUILT 2026-08-26** as a Stats tab on the Customers page — demo-testing
before DoorDash-Storefront-segment outreach still applies._

## Why this exists (the strategic frame)

Plateful's differentiator against DoorDash Storefront is not price — a Storefront
pickup order costs the restaurant ~2.9% + 30¢ vs our 4% + Stripe. The
differentiator is **ownership**: DoorDash's Storefront addendum gives merchants
order info but says they "will not own such Customer Data" and bars remarketing.
Our pitch is "the customer is *yours*."

Today that claim is true at the database layer and invisible at the product
layer: customer identity, order history, and loyalty balances are all stored
per-restaurant, but **no tenant-admin surface shows any of it**. An owner has
their customer list and cannot see it, search it, export it, or learn from it.
This plan closes that gap. (Tracked as todo.md §4; this doc is the spec.)

## Data foundation (verified 2026-08-18 — this is mostly UI over existing data)

- `restaurant_customer` pivot already stores per-restaurant counters:
  `first_ordered_at`, `last_ordered_at`, `total_orders`, `total_spent_cents`
  (columns verified against the live schema). Contact info (email/phone) lives
  on `User` — a join away.
- `loyalty_points` is keyed (user_id, restaurant_id) → per-restaurant balance.
- Orders carry user_id + restaurant_id for the stats queries the pivot counters
  can't answer (new-vs-returning revenue by period).
- Tenant scoping/authorization conventions already exist across the
  TenantAdmin controllers — same middleware + patterns apply.

## Phase 1 — the Customers page + CSV export (the ownership proof)

A `TenantAdmin\CustomersController@index` + Inertia page at
`/{subdomain}/customers`, listing each customer with:

- name, email (from `User`)
- total orders, lifetime spend, first/last order date (from the pivot)
- loyalty balance
- sortable columns, search by name/email, "ordered in last 30/90 days" filter

Plus **Export CSV** — one button, streams the same columns. The export is not a
convenience feature; it is the sales demo. In front of a Storefront restaurant
the move is: click Export, hand them the file, say "that's your list — take it
to Mailchimp or to a competitor; DoorDash's contract says you'll never have
this." Build the page *around* that moment.

**Amendment (2026-08-19): marketing consent capture ships in this phase.** The
campaigns plan (`docs/campaigns_plan.md`) decided strict opt-in — which means the
campaignable list only accrues from the day the checkbox exists, so capture is
pulled forward out of the campaigns phase. Added Phase 1 scope (full data model
and rationale in the campaigns doc):

- consent columns on `restaurant_customer` + append-only
  `marketing_consent_events` audit table
- unchecked-by-default opt-in checkbox at checkout (logged-in customers;
  persisted at order materialization), plus a per-restaurant toggle on the
  storefront `/account` page
- signed-URL unsubscribe endpoint (login-free) — built now, used by Phase 3
- Customers page shows a "Marketing ✓" badge, opted-in count, opted-in filter;
  CSV export gains consent columns so the exported list is *legally usable*
  elsewhere, not just data
- effort: ~1 extra session on top of the original Phase 1 estimate

Notes:
- Respect soft-deleted users (deleted accounts should not export contact info).
- Guests/phone-order customers don't exist in the data — the page shows online
  ordering customers only; label it honestly. (Guest consent capture is a
  flagged decision in the campaigns doc — deferred.)
- Tests: scoping (restaurant A admin never sees restaurant B customers), export
  contents, member-role authorization, consent persistence + cross-restaurant
  consent scoping, logged-out unsubscribe.

## Phase 2 — the regulars stats view (the editorial thesis as a product screen)

_BUILT 2026-08-26 from the build spec `docs/customers_stats_implementation_plan.md`
(placement decided: a Stats tab on the Customers page)._

The Stories publication argues "≈60% of restaurant revenue is repeat guests"
(Olo) and "your regulars are the expensive part." This screen shows the owner
*their own* number:

- repeat rate: % of orders / % of revenue from customers with 2+ orders
- new vs returning revenue split by month (chart)
- top customers table (by lifetime spend)
- average orders per customer, median days between orders

Placement: either a "Stats" tab on the Customers page or tiles on the existing
dashboard — decide when building; the dashboard's `DashboardStatsData` is
deliberately operational (today's orders/pending), so a separate view likely
fits better. Marketing tie-in: screenshots of this page (with permission)
become the lighthouse money-story exhibit.

## Phase 3+ — campaigns (spec'd 2026-08-19, own doc)

- **Campaigns** (email, then SMS) are now fully planned in
  `docs/campaigns_plan.md`: Phase 3 = email campaigns (Resend send API on a
  dedicated marketing domain, strict opt-in, platform-enforced compliance +
  abuse controls), Phase 4 = SMS (hard-gated behind TCPA consent capture,
  10DLC registration, and actual demand). Consent capture itself moved into
  Phase 1 (amendment above). Phase 1's CSV export remains the bridge until
  Phase 3 ships.
- **Loyalty redemption**: earn path shipped, spend path missing — todo.md §10
  owns that decision; don't fork it here.

## Sequencing triggers

1. Lighthouse restaurant live and taking orders → build Phase 1 within its
   first weeks ("running smoothly" includes the owner seeing who's ordering).
2. Phase 2 before the ~60-day lighthouse money story (the screenshot matters).
3. Both phases shipped and demo-tested before any DoorDash-Storefront-segment
   outreach begins — that pitch is *only* the ownership pitch.
