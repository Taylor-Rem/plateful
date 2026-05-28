# Laravel Best-Practices Audit — Plateful

_Date: 2026-05-28_
_Scope: Broad sweep of the codebase to flag the highest-impact deviations from Laravel/PHP conventions before the next round of major changes._

## Status Updates

- **2026-05-28** — Storefront menu-editing migration landed alongside partial fixes:
  - **#2 (Policies)** — partial. Added `MenuItemPolicy` and `AuthorizesRequests` on base `Controller`. `MemberPolicy`, `OrderPolicy` still outstanding (#10 still open).
  - **#5 ($fillable)** — partial. `MenuItem` switched to explicit `$fillable`. `Restaurant` still uses `$guarded = []`.
  - **#13 (image transaction)** — partial. New `Storefront\Admin\MenuItemController` performs image storage inside the surrounding `DB::transaction`. The legacy admin-host item controller was deleted, so the original location is gone.

---

## Overall Health

The codebase demonstrates generally solid architectural discipline: consistent use of Data Transfer Objects (Spatie Data), FormRequests, an `Actions/` + `Services/` layer, and a thought-through multi-tenant model with global scopes. Tests are reasonably broad (~8k lines of tests vs ~2.7k lines of controllers). The main weaknesses cluster in three areas: (1) **authorization is enforced inline rather than through Policies**, which is risky for a multi-tenant app; (2) **synchronous side-effects** (mail, image handling) in request paths that should be queued or transactional; and (3) **schema hygiene** — missing compound indexes, status counts via N+1, money/JSON columns without enforcement. None of these are foundational rewrites — all are fixable incrementally before scaling.

---

## High-Severity Findings

### 1. Synchronous Mail Sending During Request Cycle

**Severity:** High
**Category:** Queue/Job Usage
**Locations:**
- `app/Http/Controllers/OwnerSignupController.php:70`
- `app/Http/Controllers/Admin/SuperAdmin/SignupsController.php:116`
- `app/Http/Controllers/Admin/SuperAdmin/SignupsController.php:140`

**Problem:** Signup notifications are sent with `Mail::to()->send()` instead of `->queue()`. If SMTP is slow or unavailable, the request blocks.

**Why it matters:** Signup approval/rejection are user-facing workflows. A single slow mail provider can cascade into request timeouts.

**Suggested fix:** Switch all three calls to `->queue()` and confirm `QUEUE_CONNECTION` is configured in production.

---

### 2. No Policy/Authorization Pattern for Tenant-Scoped Resources

**Status:** Partially addressed (2026-05-28) — `MenuItemPolicy` added; `MemberPolicy` and `OrderPolicy` still pending.

**Severity:** High
**Category:** Authorization Gaps
**Locations:**
- `app/Http/Controllers/Admin/TenantAdmin/MembersController.php:50-88`
- `app/Http/Controllers/Admin/TenantAdmin/MenuItemController.php:73-122`
- `app/Http/Controllers/Storefront/OrderController.php:17-45`

**Problem:** Cross-tenant access is gated by middleware + inline checks (e.g., `if ($member->id === $request->user()->id)`). There are no `Policy` classes. Authorization rules are scattered and easy to miss when copy-pasted to a new endpoint.

**Why it matters:** Multi-tenant apps are one missed check away from cross-tenant data leaks. Centralizing rules in Policies makes them auditable and reusable.

**Suggested fix:** Add `MenuItemPolicy`, `MemberPolicy`, `OrderPolicy`. Call `$this->authorize(...)` in controllers (or use implicit policies via route model binding). This is the single highest-leverage change before adding new tenant-facing surface area.

---

### 3. Missing Compound Index on `orders.(restaurant_id, status)`

**Severity:** High
**Category:** Migration / Schema Smells
**Locations:** `database/migrations/2026_05_18_170004_create_orders_tables.php:17`

**Problem:** `status` is indexed individually, but admin dashboards filter by `restaurant_id` first and then `status`. The current single-column index forces extra row scans once data grows.

**Why it matters:** Every kitchen/admin dashboard pays for this on each pageload. It will become noticeable well before the SaaS reaches material scale.

**Suggested fix:** Add `$table->index(['restaurant_id', 'status'])` via a new migration. Pair with fix #4.

---

### 4. Order Status Counts via N+1 Loop

**Severity:** High
**Category:** N+1 Queries
**Locations:** `app/Http/Controllers/Admin/TenantAdmin/OrdersController.php:50-56`

**Problem:** Loops `OrderStatus::cases()` and runs a separate `.count()` per status — 5+ queries per page load. The same controller already shows the correct pattern (`groupBy('status')`) elsewhere; this spot is inconsistent (see `Admin/SuperAdmin/SignupsController.php:35-39` for the right shape).

**Why it matters:** Hot path on the busiest screen in the app. Multiplies with concurrent staff users.

**Suggested fix:** Single `groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status')`, fill in zeros from the enum.

---

## Medium-Severity Findings

### 5. Loose `$guarded = []` on Restaurant and MenuItem Models

**Status:** Partially addressed (2026-05-28) — `MenuItem` now uses explicit `$fillable`. `Restaurant` still open.

**Severity:** Medium
**Category:** Mass-Assignment & Fillable Hygiene
**Locations:**
- `app/Models/Restaurant.php:26`
- `app/Models/MenuItem.php:19`

**Problem:** Both models use `$guarded = []`. Today FormRequests guard the inputs, but adding a sensitive column later (e.g., `stripe_account_id`, `trial_ends_at`, `is_active`) silently makes it mass-assignable.

**Why it matters:** Landmine. The bug class (mass-assignment) is one of Laravel's classic foot-guns; explicit `$fillable` is cheap insurance.

**Suggested fix:** Switch to explicit `$fillable` listing only user-editable columns.

---

### 6. Unvalidated Modifiers JSON on Order Items

**Severity:** Medium-High
**Category:** Data Integrity
**Locations:**
- `database/migrations/2026_05_18_170004_create_orders_tables.php:40`
- `app/Services/OrderPlacement.php:120`

**Problem:** Cart modifier selections are persisted as JSON without schema validation against the item's current modifier template. Orders are immutable business records — corrupt or mismatched JSON cannot be reconciled later.

**Why it matters:** Receipts, refunds, and reporting all read from this column. A bad payload here is a customer-support incident.

**Suggested fix:** Validate selections against the item's template inside `OrderPlacement` before persisting. Consider a value-object cast on `OrderItem::modifiers` so reads are typed.

---

### 7. Restaurant Hours Not Eager-Loaded for List Renders

**Severity:** Medium
**Category:** N+1 Queries
**Locations:**
- `app/Models/Restaurant.php:164-206` (`isOpenAt()`, `nextOpenAt()`, `formatNextOpenAt()`)
- `app/Http/Controllers/Admin/TenantAdmin/MenuController.php:18-20`

**Problem:** `hours()` is accessed inside these helpers. `RestaurantData::fromModel()` defends with a `relationLoaded()` check, but callers that render multiple restaurants don't pre-load `hours`, producing 1+N.

**Why it matters:** Any future "browse restaurants" or super-admin list page silently fans out queries.

**Suggested fix:** Add `->with('hours')` at the few call sites that list restaurants. Optionally add a `$with = ['hours']` default once we're confident the cost is always paid.

---

### 8. `RestaurantSignup` Status as String Constants Instead of Enum

**Severity:** Medium
**Category:** Enums vs Magic Strings
**Locations:** `app/Models/RestaurantSignup.php:13-17`

**Problem:** Status uses `STATUS_PENDING`/`STATUS_APPROVED`/`STATUS_REJECTED` constants while the rest of the app (`OrderStatus`, `RestaurantRole`, `RestaurantStatus`) uses native enums. The signup index also accepts string filters without enum validation (`SignupsController.php:25`).

**Why it matters:** Inconsistency invites bugs and undermines the type-safety the rest of the codebase gets from enums.

**Suggested fix:** Add `RestaurantSignupStatus` enum, cast on the model, and accept it via FormRequest in the filter.

---

### 9. Test Coverage Gap: Staff vs Admin Authorization

**Severity:** Medium
**Category:** Test Coverage
**Locations:** `tests/Feature/Admin/`, `app/Http/Controllers/Admin/TenantAdmin/`

**Problem:** `RestaurantAccessTest` covers membership, but there's no per-route test that Staff get 403 from admin-only endpoints (`items.create`, `templates.store`, `members.index`, `billing.*`, `settings.*`). The `admin.restaurant.admin` middleware is unverified at the route level.

**Why it matters:** A future route reshuffle could silently expose admin endpoints to Staff with nothing in CI to catch it.

**Suggested fix:** Add a dataset-driven test that iterates the admin-only routes and asserts 403 for a Staff actor. Pairs naturally with fix #2 (Policies).

---

### 10. Inline Order Authorization Will Get Duplicated

**Severity:** Medium
**Category:** Authorization Gaps
**Locations:** `app/Http/Controllers/Storefront/OrderController.php:21-35`

**Problem:** Order visibility is enforced inline (cookie token + user id). The moment a second surface needs it (receipt email link, customer order history API, kitchen kiosk), the logic gets copy-pasted — and the copies will drift.

**Why it matters:** This rule directly governs cross-customer privacy.

**Suggested fix:** Move into `OrderPolicy::view(?User $user, Order $order, ?string $cookieToken = null)` and call via `Gate::allows(...)`.

---

### 11. Missing Index on `order_items.menu_item_id`

**Severity:** Medium
**Category:** Schema Smells
**Locations:** `database/migrations/2026_05_18_170004_create_orders_tables.php:36`

**Problem:** Foreign key exists, no index. Reverse lookups ("how many times was this item ordered?") and integrity checks scan.

**Suggested fix:** `->foreignId('menu_item_id')->index()` in a follow-up migration.

---

## Lower-Severity Findings

### 12. Non-Atomic Subdomain Reservation on Signup

**Severity:** Low
**Category:** Schema Smells / Race Conditions
**Locations:** `database/migrations/2026_05_18_170000_create_restaurants_table.php`, `Admin/SuperAdmin/SignupsController.php:75`

**Problem:** Two pending signups can simultaneously propose the same subdomain. Approval-time check is not transactional with the write.

**Suggested fix:** Either a partial unique index on `proposed_subdomain WHERE status = 'pending'` (Postgres) or wrap the approve flow in `DB::transaction` + `lockForUpdate` on the row.

---

### 13. Menu Item Image Operations Outside DB Transaction

**Status:** Addressed (2026-05-28) for the new storefront controller. Legacy admin-host controller was deleted as part of the menu-editing migration.

**Severity:** Low
**Category:** Data Consistency
**Locations:** `app/Http/Controllers/Admin/TenantAdmin/MenuItemController.php:65-68`

**Problem:** Image upload/delete sits outside the surrounding transaction. A failure between filesystem and DB leaves them out of sync.

**Suggested fix:** Defer the filesystem write until after `DB::commit()` (or use a transactional outbox pattern). Low priority — the cost is a stale file, not a broken order.

---

### 14. `templateOptions()` Loaded Per-Request, Not Eagerly

**Severity:** Low
**Category:** N+1 Queries
**Locations:** `app/Http/Controllers/Admin/TenantAdmin/ItemTemplateController.php:202-210`

**Problem:** `->with('groups.options')` is applied inside a helper rather than at the index query. Fine while template counts are small; revisit if a restaurant gets > ~100 templates.

---

### 15. Pivot Update Without Re-check in MembersController

**Severity:** Low
**Category:** Race Conditions
**Locations:** `app/Http/Controllers/Admin/TenantAdmin/MembersController.php:68`

**Problem:** `updateExistingPivot()` silently no-ops if a concurrent request removed the member. Unlikely in practice.

**Suggested fix:** Wrap in `DB::transaction` and re-check the row, or assert the affected-rows result.

---

## Recommended Order of Attack

If you only do a few before the next big push, do these — they're the ones that get harder to retrofit later:

1. **Policies for tenant-scoped resources** (#2, #10) — every new feature you add will either use them or entrench the inline pattern further.
2. **Switch signup mail to `->queue()`** (#1) — one-line fix, immediate user-experience win.
3. **Add the `(restaurant_id, status)` index + collapse the status-count loop** (#3, #4) — small migration + small controller refactor, big hot-path win.
4. **Tighten `$fillable` on `Restaurant` and `MenuItem`** (#5) — cheap insurance before more columns get added.
5. **Validate order modifiers JSON in `OrderPlacement`** (#6) — order immutability is a hard requirement for billing/refunds.

The remaining findings are worth scheduling but won't compound into problems if deferred a sprint or two.
