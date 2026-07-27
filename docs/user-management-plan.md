# User Management — Implementation Plan

**Status: 📝 PLANNED** · Phase 1 (user soft-deletes) shipped 2026-07-27. This
doc covers Phase 2: a super-admin **Users** console for viewing and managing
every account (admins, customers, and orphans), built on the Phase 1 foundation.
Scope to be locked with Taylor before a build session starts.

---

## 1. Why (the problem)

The platform can create users (owner signup, customer registration, Google
OAuth, admin invitations) but has **no place to see or manage the full user
population**. Today:

- The super-admin **Admins** page (`Admin/SuperAdmin/Admins`) only lists users
  who are super admins *or* have a `restaurant_user` pivot. Its "Remove access"
  action ([`AdminsController@destroy`](../app/Http/Controllers/Admin/SuperAdmin/AdminsController.php)) only **demotes** — it clears
  `is_super_admin` and detaches restaurant pivots, keeping the user row.
- **Customers** (order-only / `restaurant_customer` users) have no admin surface
  at all. They can only self-serve under `Storefront/Account/*`.
- **Orphans** — a user with no super-admin flag and no pivots (e.g. a former
  admin whose access was removed) — are invisible everywhere in the admin UI.
- The only way to actually delete a user is the customer self-service path
  ([`Settings/ProfileController@destroy`](../app/Http/Controllers/Settings/ProfileController.php), a hard `forceDelete`). An admin
  cannot remove or recover an account.

The symptom that triggered this work: a removed admin's email stayed "taken" on
the get-started wizard because the user record was never deleted, and there was
no tool to delete it.

## 2. What Phase 1 already delivered (the foundation)

Shipped 2026-07-27 — do **not** redo this; build on it. See
[`project_user_soft_deletes`](memory) for the durable summary.

- `users` table uses `SoftDeletes` (`deleted_at`). Migration:
  `database/migrations/2026_07_27_162914_add_soft_deletes_to_users_table.php`.
- `users_email_unique` and `users_google_id_unique` are **partial** unique
  indexes (`WHERE deleted_at IS NULL`) — a deleted account frees its email and
  Google link for a new live account. Portable across Postgres (dev/prod) and
  SQLite (tests).
- The three email-uniqueness validators ignore trashed rows via
  `->whereNull('deleted_at')`: `ProfileValidationRules`, `OwnerSignupRequest`,
  `Admin/SuperAdmin/UpdateAdminRequest`.
- Auth is already safe with trashed users: Fortify's provider and
  `GoogleController` both go through `User::query()`, whose SoftDeletes global
  scope excludes trashed rows — a deleted account can't log in and isn't
  resurrected; a fresh login creates a new account.
- Self-service deletion is an explicit `forceDelete()` (genuine PII removal);
  **admin-initiated deletion is the recoverable soft delete this plan adds.**

Foundation gap to fill in Phase 2: `User` has no `orders()` relation yet
(orders link via `Order.user_id`). Add it for the impact view / force-delete
guard.

## 3. Target architecture

Mirror the **restaurant lifecycle** pattern exactly — it already solves the same
shape (list + soft delete + restore + guarded force delete) and sets the naming
and route-binding conventions.

**Routes** — add to [`routes/super-admin.php`](../routes/super-admin.php) inside the existing
`admin.super.` group (prefix `/super`, middleware `['admin','super']`). Follow
the restaurant convention: the live-row action uses route-model binding; restore
and force-delete take a raw id and resolve `onlyTrashed()` in the controller
(the default binding excludes trashed rows).

```php
Route::get('/users', [SuperAdmin\UsersController::class, 'index'])->name('users.index');
Route::get('/users/{user}', [SuperAdmin\UsersController::class, 'show'])->name('users.show');
Route::delete('/users/{user}', [SuperAdmin\UsersController::class, 'destroy'])->name('users.destroy');       // soft delete (bound, live row)
Route::post('/users/{id}/restore', [SuperAdmin\UsersController::class, 'restore'])->name('users.restore');   // onlyTrashed()
Route::delete('/users/{id}/force', [SuperAdmin\UsersController::class, 'forceDelete'])->name('users.forceDelete'); // onlyTrashed(), guarded
```

**Controller** — new `App\Http\Controllers\Admin\SuperAdmin\UsersController`.
Keep the existing `AdminsController` focused on *admin access* (invitations,
super-admin toggle, remove-access); `UsersController` owns *account lifecycle*.
They overlap intentionally the way `RestaurantsController` and
`RestaurantLifecycleController` do.

**Page** — new `resources/js/pages/Admin/SuperAdmin/Users.vue` (list) and a
`Users/Show.vue` detail (or a Sheet/dialog drawer — see §5). Add the **Users**
item to the super-admin sidebar **Platform** group (icon: `Users`), alongside
Restaurants / Earnings / Admins. Update the nav-visibility tests.

## 4. Backend spec

### `index` — the list

One query over `User::withTrashed()` with server-side search + filter, paginated
(`->paginate(25)->withQueryString()`), returning a typed row shape. Derive a
"type" for each user so the UI can badge them:

- **Super admin** — `is_super_admin`.
- **Restaurant admin/staff** — has `restaurant_user` rows (include names/roles).
- **Customer** — has `restaurant_customer` rows.
- **Orphan** — none of the above (former admin, or self-signup with access
  since removed).
- **Deleted** — `deleted_at !== null` (orthogonal; shown as a state badge).

Eager-load `restaurants:id,name,subdomain` and count customer restaurants /
orders to avoid N+1. Filters: `all | admins | customers | deleted`. Search:
`name`/`email` `ILIKE`. Default order `orderBy('name')`, trashed sorted normally
but visibly badged.

### `show` — impact detail

Before deleting, surface the footprint so deletion isn't blind:

- restaurants they admin (name, role) and restaurants they've ordered from,
- order count + lifetime spend (via a new `User::orders()` relation →
  `Order::where('user_id', …)`), most recent order date,
- loyalty footprint (`LoyaltyPoints`), Stripe/customer linkage if any,
- account origin (password vs `google_id`), verified state, created/updated,
  and `deleted_at` when trashed.

### `destroy` — soft delete (recoverable)

```php
public function destroy(Request $request, User $user): RedirectResponse
{
    // Same guardrails as AdminsController::destroy / updateSuperAdmin.
    if ($user->id === $request->user()->id) { return back()->with('error', "You can't delete your own account here."); }
    if ($this->isLastSuperAdmin($user)) { return back()->with('error', "You can't delete the last super admin."); }

    $user->delete(); // soft delete; email/google_id freed by the partial indexes
    return back()->with('success', "{$user->name}'s account was deleted. You can restore it from the deleted filter.");
}
```

Decision (recommended): **do not** auto-detach `restaurant_user` pivots on soft
delete — keep the relationships intact so a restore is faithful. The SoftDeletes
scope already hides the user from admin queries. (Contrast: `AdminsController`'s
"Remove access" detaches because it is *not* deleting the account.)

### `restore` — with the one real edge case

```php
$user = User::onlyTrashed()->findOrFail($id);
```

**Collision guard (critical):** because uniqueness is partial, a *live* account
may have claimed this user's email or `google_id` while it was trashed. Restoring
would then create two live rows sharing an identifier and violate the partial
unique index. Before `->restore()`, check:

```php
$emailTaken   = User::where('email', $user->email)->exists();            // live only (global scope)
$googleTaken  = $user->google_id && User::where('google_id', $user->google_id)->exists();
if ($emailTaken || $googleTaken) {
    return back()->with('error', "Can't restore — {$user->email} is now used by another account.");
}
$user->restore();
```

### `forceDelete` — permanent, guarded

Mirror `RestaurantLifecycleController::forceDelete`: refuse when the account has
history worth preserving (orders), so financial/audit records aren't destroyed;
otherwise hard-delete.

```php
$user = User::onlyTrashed()->findOrFail($id);
if ($user->orders()->exists()) {
    return back()->with('error', "This account has order history and can't be permanently deleted. It stays soft-deleted.");
}
$user->forceDelete();
```

Reuse `isLastSuperAdmin()` (extract to a shared concern/trait or a `User` method
so both controllers share one implementation instead of duplicating it).

## 5. Frontend spec

- **List page** `Users.vue`: use the shared admin primitives from the overhaul
  (`PageHeader`, `SectionCard`, `EmptyState`) and the super-admin sidebar shell.
  A table of users with type/state badges, search box (debounced, drives the
  `search` query param via Inertia partial reload), and a filter segmented
  control (`all/admins/customers/deleted`). Pagination via query string.
- **Row actions** (dropdown-menu): View, Delete (confirm dialog), and for
  trashed rows Restore + Delete permanently. Destructive actions use the
  `dialog` primitive for confirmation (name echoed back), matching the
  restaurant delete UX. Use **Wayfinder** route helpers (`admin.super.users.*`)
  — no hardcoded URLs (per the overhaul's URL convention).
- **Detail** `Users/Show.vue` (or a slide-over Sheet): the impact data from
  `show`. A slide-over keeps the user in-list; a page is simpler to build. Pick
  one during scoping — recommend a page for v1, parity with restaurants.show.
- **Guarded states**: hide/disable "Delete" on your own row and on the last
  super admin, with a tooltip explaining why (don't rely on the server error
  alone).

## 6. Edge cases & decisions to lock

1. **What "Delete account" reaches** — this page lists *all* users (unlike the
   Admins page), so it reaches orphans like the account that triggered this work.
   ✔ resolved by using `withTrashed()` + no `whereHas` filter.
2. **Soft vs hard from this page** — recommend: Delete = soft (recoverable);
   Force delete = separate, gated, order-history-guarded escalation. (Matches
   restaurants.) Confirm with Taylor.
3. **Restore collision** — must be guarded (see §4). This is the only genuinely
   new failure mode the partial indexes introduce.
4. **Self-lockout / last super admin** — reuse existing guards.
5. **Deleting an admin who owns a live restaurant** — should this be blocked, or
   allowed (restaurant keeps running, ownership reassigned later)? Decide during
   scoping. Safe default: allow soft delete but warn in the confirm dialog if the
   user is the sole admin of any live restaurant.
6. **`isSuperAdmin()` on a soft-deleted user** — unaffected; the flag persists on
   the trashed row and drives the last-super-admin count only over live rows.
7. **Permission** — super-only, already enforced by the `super` middleware on the
   route group. Consider whether force-delete needs an extra confirmation step.

## 7. Tests (Pest, feature)

New `tests/Feature/Admin/SuperAdmin/UsersManagementTest.php`:

- index lists all user types; filters (`admins/customers/deleted`) and search
  narrow correctly; pagination works.
- `show` returns the impact payload (orders, restaurants, loyalty) without N+1.
- soft delete removes the user from live queries, frees the email (assert a new
  signup with that email now succeeds), and keeps the row `withTrashed()`.
- soft delete respects self-lockout and last-super-admin guards.
- restore succeeds on a clean email; **restore is blocked** when a live account
  now holds the email or google_id.
- force delete permanently removes an order-less account; is **refused** for an
  account with order history.
- non-super users get 403 on every route (middleware).
- a nav-visibility test asserting the Users item shows for super admins only
  (extend the existing suite from the admin overhaul).

## 8. Suggested execution order

1. Backend: `User::orders()` relation + shared `isLastSuperAdmin`/guard extraction.
2. `UsersController@index` + route + `Users.vue` list (read-only) + sidebar item + nav test.
3. `destroy` (soft) + confirm dialog + guards + tests.
4. `restore` + collision guard + tests.
5. `forceDelete` + order-history guard + tests.
6. `show` impact detail page + tests.
7. Pint, full suite, hand over commit commands.

Each step is independently shippable with its own commit and green tests, per the
admin-overhaul working style.
