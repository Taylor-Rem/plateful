# Customers Stats (Regulars View) — Implementation Plan (Customers Phase 2)

**Status:** not started · **Decisions locked 2026-08-26** · One session. Strategy/rationale:
`docs/customers_page_plan.md` Phase 2 (the "≈60% of revenue is repeat guests" editorial thesis as
a product screen). This doc is the build spec — self-contained, no prior context needed.

**Why this matters:** the screen shows a restaurant owner *their own* repeat-revenue number. It is
the second half of the ownership demo (Phase 1 Customers list + CSV shipped 2026-08-24, LIVE) and
must exist before the ~60-day lighthouse money story — the screenshot of this page (with
permission) is the planned marketing exhibit. Both phases shipped + demo-tested gate any
DoorDash-Storefront-segment outreach.

---

## Decisions (locked — do not re-litigate)

- **Placement: a "Stats" tab on the Customers page** at `/{subdomain}/customers/stats`
  (locked 2026-08-26). No new sidebar entry; the Customers entry covers both. Add a small tab
  strip (List | Stats) shared by both pages.
- **Access: admin-role only**, same as the Customers list (`admin.restaurant.admin` group).
- **No chart library.** The repo has zero charting dependencies and adding one needs approval —
  hand-roll the monthly chart as stacked bars with divs/SVG + Tailwind. One chart does not
  justify a dependency.
- **Honest labeling everywhere:** metrics cover online orders only. Identified (logged-in)
  customers power the repeat metrics; guest-checkout orders (`orders.user_id` null) appear as
  their own slice in the revenue chart so totals reconcile with what the owner knows they sold.

## What already exists (reuse, don't rebuild)

- `Admin/TenantAdmin/CustomersController` + `resources/js/pages/Admin/TenantAdmin/Customers/Index.vue`
  — Phase 1. The stats tab is a sibling: same route group, same layout, same `RestaurantData`
  prop contract. Its `customersQuery()` shows the soft-delete-safe join pattern.
- `restaurant_customer` pivot: `total_orders`, `total_spent_cents`, `first/last_ordered_at`,
  marketing consent columns. `App\Models\RestaurantCustomer` (+ `emailOptedIn()` scope).
- `orders`: `restaurant_id`, `user_id` (nullable = guest), `status` (`App\Enums\OrderStatus`),
  `total_cents`, `placed_at`, index `(restaurant_id, placed_at)`. Exclude
  `OrderStatus::Cancelled` from all money/count metrics (match how Dashboard treats revenue —
  check `DashboardController` and mirror its exclusions exactly).
- UI primitives: `components/admin/StatCard.vue` (see `Dashboard.vue` usage), `PageHeader.vue`,
  `EmptyState.vue`; `formatCents` in `resources/js/lib/orderStatus.ts`.
- `App\Data\CustomerData` — reuse for the top-customers table rows (it expects the
  `loyalty_points_balance` subselect + loaded user; copy the Phase 1 query shape).
- Timezone: restaurants have a timezone the Dashboard uses for "today" — use the same for month
  bucketing (`placed_at` is stored UTC).

**Repo conventions:** identical to the campaigns build — see
`docs/campaigns_implementation_plan.md` § "Repo conventions the executing session must follow"
(tenant-admin controller pattern, Spatie Data + `#[TypeScript]` + `typescript:transform`,
Wayfinder regeneration, Pest host-trick tests with `beforeEach` primary_domain config, helpers in
`tests/Feature/Admin/CustomerTestHelpers.php`, `vendor/bin/pint --dirty --format agent` before
finishing). That section is authoritative; read it.

---

## Metric definitions (be exact — tests pin these)

Scope for all: this restaurant's orders, `status != cancelled`. "Identified" = `user_id` not null.

1. **Repeat rate (headline pair):** among identified orders, an order is a *repeat order* when
   the same user has an earlier non-cancelled order at this restaurant (earlier `placed_at`,
   tie-break `id`). Report **% of identified orders that are repeat** and **% of identified
   revenue (total_cents) from repeat orders**. This is the Olo-thesis number — order-level, not
   customer-level (a customer's first order counts as "new" even if they later became a regular).
2. **New vs returning revenue by month (the chart):** last 12 calendar months in the
   restaurant's timezone. Per month, three stacked series of `sum(total_cents)`:
   *new* (identified, non-repeat orders), *returning* (identified repeat orders), *guest*
   (`user_id` null). Months with zero revenue still render as empty columns.
3. **Top customers:** top 10 pivot rows by `total_spent_cents` (soft-deleted users excluded, same
   as Phase 1), rendered with name/email, orders, lifetime spend, last order — reuse
   `CustomerData` and the Index table styling.
4. **Average orders per customer:** `sum(total_orders) / count(*)` over this restaurant's pivot
   rows with `total_orders >= 1` (non-deleted users), one decimal.
5. **Median days between orders:** over all *consecutive same-user order pairs* (identified,
   non-cancelled, this restaurant), the median gap in days. Pull `(user_id, placed_at)` ordered
   and compute in PHP — restaurant order counts are small; don't build window-function SQL for
   this. Null when fewer than 2 pairs exist (tile renders an em dash, like Dashboard's
   `avgTicketCents`).

Implementation note for #1/#2: a single pass works — fetch each identified order with a
"first `placed_at` per user" (one grouped subquery joined in, or compute per-user firsts in PHP);
repeat = `placed_at` after (or same-timestamp-later-id than) that user's first. Whatever the
approach, the cross-restaurant property is non-negotiable: a user's orders at another restaurant
must never make their first order here look like a repeat.

## Build items

**Backend:**
- `CustomersController@stats` (same controller, keeps the tab pairing obvious) + route
  `GET /customers/stats` → `admin.restaurant.customers.stats`, inside the existing admin-only
  group in `routes/admin.php`, **registered near the existing customers routes** (note
  `/customers/export` already exists — order routes so nothing shadows).
- `App\Data\CustomerStatsData` (Spatie, `#[TypeScript]`): repeat order/revenue percentages
  (nullable when no identified orders), monthly series (12 entries: `month` label +
  new/returning/guest cents), avg orders per customer (nullable), median days between orders
  (nullable), plus context counts (identified customers, identified orders). Follow
  `DashboardStatsData`'s nullable-with-doc-comment style.
- Consider `Inertia::defer()` for the stats payload with a pulsing skeleton (repo rule when using
  deferred props); fine to skip if computation is fast — measure, don't assume.

**Frontend:**
- `resources/js/pages/Admin/TenantAdmin/Customers/Stats.vue` + a small `CustomersTabs.vue`
  co-located component (List | Stats) added to both Index.vue and Stats.vue, styled like the
  storefront `AccountTabs.vue` pattern but with admin tokens.
- Layout: headline `StatCard` row (repeat revenue %, repeat orders %, avg orders/customer, median
  days between orders) → stacked-bar chart (12 months, legend for new/returning/guest, y-axis in
  dollars via `formatCents`, `tabular-nums`, accessible fallback text) → top-10 customers table.
- Empty state (`EmptyState.vue`) when there are no orders yet — sell it ("Once orders come in,
  you'll see how much of your revenue comes from regulars").
- Honest footnote: "Online orders only. Repeat metrics cover signed-in customers; guest orders
  shown separately."
- Wayfinder (`php artisan wayfinder:generate`), `php artisan typescript:transform`,
  `npm run build` (see memory/README for the Node 22 + Herd PATH incantation), Prettier + ESLint
  on touched files.

**Tests** (`tests/Feature/Admin/CustomerStatsTest.php`, reuse `AdminOrderTestHelpers.php` +
`CustomerTestHelpers.php` — `makeOrder()` accepts `user_id`/`placed_at`/`status`/`total_cents`
overrides):
- Cross-restaurant scoping (`assertForbidden` on another restaurant's subdomain) + staff-role
  forbidden.
- Repeat-rate fixture: e.g. user A with 3 orders, user B with 1, one guest order → pinned
  percentages for orders and revenue; cancelled orders excluded; **user A's order at another
  restaurant does not alter this restaurant's numbers** (the leak test).
- Monthly buckets: orders seeded across specific months land in the right buckets with the right
  new/returning/guest split; 12 entries always returned.
- Median gap: known `placed_at` sequence → pinned value; single-order-only restaurant → null.
- Top customers excludes soft-deleted users; empty restaurant returns zeros/nulls (no division
  errors) and the page renders.

## Wrap-up checklist for the executing session

1. Full targeted test run green + `vendor/bin/pint --dirty --format agent`.
2. Update this doc's Status line, `docs/customers_page_plan.md` (mark Phase 2 built), and
   todo.md §4's customers item.
3. Do not commit autonomously — hand the repo owner the `git add`/`git commit` commands.

**Effort:** ~1 session.
