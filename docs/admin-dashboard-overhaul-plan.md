# Admin Dashboard Overhaul — Implementation Plan

**Status:** in progress · **Scope locked 2026-07-24** with Taylor: full overhaul (nav/layout + shared UI + backend cleanup + real Dashboard), broken into independently shippable sessions, each with its own commit(s) and green tests.

### Progress

| Session | What | State |
|---|---|---|
| **0** | This plan doc | ✅ Done (lands with Session 1's first commit) |
| **1** | Route architecture: split the admin host route file; renames; `{restaurant:subdomain}` | 🔄 In progress |
| **2** | Shared props (`isSuperAdmin`) + authorization tidy (`RestaurantPolicy::manage`) | ⬜ |
| **3** | Tenant admin sidebar layout (the big visible change) | ⬜ |
| **4** | Super admin sidebar layout | ⬜ |
| **5** | Shared primitives (PageHeader/StatCard/SectionCard/EmptyState) + consistency sweep | ⬜ |
| **6** | Wayfinder URL sweep (kill the ~45 hardcoded `/${subdomain}/...` strings) | ⬜ |
| **7** | Invitation consolidation (`AdminInvitationService`) + platform revoke | ⬜ |
| **8** | Controller splits (`RestaurantLifecycleController`) + `OnboardingSteps` extraction | ⬜ |
| **9** | Real Dashboard: KPIs, recent orders, setup status (backend + frontend) | ⬜ |
| **10** | Restaurant switcher v2 + nav visibility tests + polish | ⬜ |

**Execution order:** 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10. Dependencies: S1 gates all
Wayfinder-consuming frontend work (S3/S4/S6); S2 gates the global nav (S3/S4); S5 gates the
Dashboard UI; S8's `OnboardingSteps` gates S9's setup surface. S7 is independent — it can slot
anywhere after S1.

---

## 1. Why (the problem)

The admin console grew one feature at a time and it shows:

- **Navigation**: a flat, hardcoded top nav in `pages/Admin/TenantAdminLayout.vue` — no active
  state, no grouping, no mobile handling. Templates and POS pages are unreachable from the nav
  (Templates only via a button in Menu.vue, POS only from deep inside Onboarding.vue).
- **Super admin has no layout at all**: five pages each roll their own header with inconsistent
  links and widths; `/super/earnings` and `/super/admins` are discovered by luck.
- **The Dashboard is a stub**: four placeholder KPI tiles rendering literal `—`.
- **No shared primitives**: the card div (`rounded-lg border border-border bg-card`) is
  hand-written 56 times across 23 of 24 admin pages; headings, `<Head>` titles, and container
  widths drift page to page.
- **URLs are hardcoded**: ~45 interpolations of `` `/${restaurant.subdomain}/...` `` while a
  full generated Wayfinder route tree sits unused.
- **Backend structure lies**: `routes/super-admin.php` holds the ENTIRE admin host (webhooks
  included); three invitation controllers duplicate each other; `SuperAdmin\RestaurantsController`
  mixes four concerns; authorization is middleware-only on the admin host but policy-based on the
  storefront, with redundant policy calls sprinkled in between.

## 2. Target information architecture

**Tenant admin** (`admin.<domain>/{subdomain}/...`) — grouped sidebar (shadcn sidebar kit,
already installed; mobile Sheet + icon-collapse + cookie-persisted state for free):

| Group | Items (icon) | Visibility |
|---|---|---|
| *(header)* | RestaurantSwitcher (logo + name; v1 links to `/`, v2 lists restaurants) | all |
| **Operations** | Dashboard (`LayoutGrid`), Orders (`ShoppingBag`), Kitchen (`ChefHat`), Hours (`Clock`) | all members |
| **Menu** | Menu (`BookOpen`) all members; Templates (`LayoutTemplate`) admin |
| **Manage** | Setup/Finish setup (`ClipboardCheck`, amber badge until setup complete), Payouts (`Banknote`), Team (`Users`), Delivery (`Truck`), POS (`Plug`), Settings (`Settings`) | admin only |
| *(footer)* | Visit storefront (external), user menu (appearance toggle, log out) | all |

**Super admin** (`admin.<domain>/super/...`) — same shell, **Platform** group: Restaurants
(`Store`), Earnings (`ChartNoAxesColumn`), Admins (`ShieldCheck`); footer links back to `/`.

Chromeless by design (no sidebar): Kitchen (full-screen board), Onboarding (wizard),
MenuImportReview, Home (picker), NoAccess, invitation accept, Login.

**Backend target**: `routes/webhooks.php` (externally registered URLs, frozen) +
`routes/super-admin.php` (super only, registered before the tenant wildcard) +
`routes/admin.php` (everything else on the admin host). Registration order:
storefront → webhooks → super-admin → admin → web.

## 3. Decisions locked

- **Sidebar with groups** over a grouped top nav — the roadmap (customers, loyalty, disputes)
  keeps adding destinations.
- **Menu-item/site editing stays on the storefront** edit-mode (amber admin bar); the admin Menu
  page manages categories/templates and links out clearly. No rebuild.
- **Build admin-specific sidebar components** (`components/admin/*`); the starter-kit
  `AppSidebar`/`NavMain`/`AppLayout` are LIVE for customer settings pages — reuse only
  `ui/sidebar/**` primitives and `useCurrentUrl()`. Do not reuse `UserMenuContent` (its profile
  link targets the primary host).
- **Wayfinder everywhere** for admin URLs; prerequisite is annotating `{restaurant:subdomain}`
  so generated helpers type the param as the subdomain string (the runtime `Route::bind` already
  resolves by subdomain; without the annotation Wayfinder types it as an ID and URLs would 404).
- **Middleware is the authorization layer on the admin host**; policies exist only where
  middleware can't reach (storefront-host admin actions, POS OAuth callbacks where the
  restaurant arrives via OAuth `state`). `RestaurantPolicy` collapses to a single `manage()`.
- **Flash convention**: `success` / `error` only (the only keys `HandleInertiaRequests` shares —
  fixes the silently-dropped platform-invitation flash).
- **Dashboard KPI definitions**: "today" = restaurant-timezone day (`timezone ?: 'America/New_York'`)
  against UTC `placed_at`; orders today excludes cancelled; revenue = `SUM(total_cents −
  refunded_cents)` where captured (holds aren't money; commission columns are super-earnings
  concerns, not owner-facing); avg ticket null-safe; **pending is NOT day-bounded** (it's the
  action queue). Needs a `(restaurant_id, placed_at)` index. No caching.
- **Skip**: `DeliveryIntegrationsController` split (cohesive screen, no OAuth dance); mass-moving
  the 21 flat `Requests/Admin` files; `MemberPolicy` registration (manual instantiation is
  deliberate — the model is `User`).

## 4. Sessions (detail)

### Session 1 — Route architecture
**Commit A (pure moves, zero behavior change):** create `routes/webhooks.php` +
`routes/admin.php`; rewrite `routes/super-admin.php` to super-only; reorder `bootstrap/app.php`
registration (super before the `{restaurant}` wildcard so `/super/*` can never be shadowed);
new `RouteArchitectureTest` pinning the three webhook URLs.
**Commit B (renames + fixes):** `RequireSuperAdmin` redirects guests to login (mirrors
`RequireAdmin`); `admin.super.earnings` → `.earnings.index`; `onboarding.stripe.*` → `stripe.*`
at `/{restaurant}/stripe/*` (Stripe Connect is permanent, not an onboarding feature);
`menuImport.store` path → `/menu-import`; annotate `{restaurant:subdomain}` (7 spots);
regenerate Wayfinder + fix the two Vue files importing stripe/menuImport helpers.
Tests hit literal URLs, so only `StripeConnectTest` + `MenuImportTest` need path edits.

### Session 2 — Shared props + authorization tidy
`HandleInertiaRequests`: share `auth.isSuperAdmin`; dedupe `canEditMenu`/`canEditSite` behind
`resolveCanManageTenant` (frontend contract untouched). Collapse `RestaurantPolicy` to
`manage()`; keep calls in `Storefront\Admin\*` + POS `callback()`s; delete redundant calls in
Stripe/Square/Clover admin-host actions. Add `NavGroup`/`NavItem` types. New
`SharedInertiaPropsTest`.

### Session 3 — Tenant admin sidebar
`layouts/admin/TenantAdminLayout.vue` (SidebarProvider shell, persistent layout via
`defineOptions({ layout })`, reads `restaurant` from page props) + `components/admin/{TenantAdminSidebar,
AdminNavGroups,AdminNavUser,RestaurantSwitcher}.vue`. Active state via `useCurrentUrl()`.
Deactivation banner and setup attention dot (`data-test="setup-attention-dot"`) carry over.
Migrate 13 pages; delete `pages/Admin/TenantAdminLayout.vue` same commit. Hrefs via Wayfinder.

### Session 4 — Super admin sidebar
`SuperAdminLayout.vue` + `SuperAdminSidebar.vue`; migrate the 5 super pages off their bespoke
headers; normalize to `max-w-5xl`; ensure non-super 403 coverage for earnings/admins.

### Session 5 — Shared primitives + consistency
`components/admin/{PageHeader,StatCard,SectionCard,EmptyState}.vue`; adopt page-by-page;
normalize `<Head>` titles/headings; delete the duplicate "Invite admin" section in
`Settings.vue` (Members is canonical); make Menu.vue's storefront-editing link prominent.

### Session 6 — Wayfinder URL sweep
Replace remaining hardcoded URL strings (Home, Kitchen, Onboarding + steps, MenuImportReview,
page bodies); remove the four `const base = computed(...)`. URL swap only — no form-style
refactors. Verify the production build generates Wayfinder with production env (host is baked
at generate time).

### Session 7 — Invitation consolidation
`app/Services/AdminInvitationService.php` (`send`/`accept`); FormRequests for the three flows;
all three controllers stay as thin shells; flash unified on `success`; platform revoke route
(`DELETE /super/admins/invitations/{invitation}`) + pending list on Admins page.

### Session 8 — Controller splits
`SuperAdmin/RestaurantLifecycleController` takes activate/deactivate/destroy/restore/forceDelete;
`app/Support/Onboarding/OnboardingSteps.php` extracted from `OnboardingController` (needed by
the Dashboard setup surface).

### Session 9 — Real Dashboard
Migration adding `(restaurant_id, placed_at)` index; `DashboardStatsData` +
lightweight `OrderSummaryData` (NOT `OrderData` — it eager-loads items); `setup` payload from
`OnboardingSteps` when not live. Frontend: KPI row on `StatCard`, recent orders linking into
Orders, setup-remaining card. `DashboardTest` covers timezone boundaries, cancelled/authorized/
refund edge cases, null avg ticket, un-bounded pending.

### Session 10 — Polish
Lazy `adminRestaurants` shared prop → RestaurantSwitcher v2; `AdminNavigationTest` for nav
visibility props; optionally (needs approval — new dependency) pest browser plugin smoke tests.

## 5. Risks

- **Webhook URLs are externally registered** — `RouteArchitectureTest` pins them; the paths
  never change, only which file they live in.
- **Wayfinder bakes the host at generate time** — the deploy build must run `wayfinder:generate`
  with production env (verify before Session 6 ships).
- **In-flight Stripe Account Links** at Session 1 deploy reference old `/onboarding/stripe/*`
  return URLs; links expire in minutes. Optional temporary redirects if deploying mid-onboarding.
- **Persistent layout switch**: lifecycle-heavy pages (Kitchen polling, Onboarding) stay
  chromeless, so no remount surprises.

## 6. Working conventions (every session)

Green tests before commit (`php artisan test --compact` scoped; full `tests/Feature/Admin` for
route/layout sessions) · `vendor/bin/pint --dirty` · frontend sessions run `npm run build` +
lint · route-touching sessions regenerate and commit `resources/js/routes/**` +
`resources/js/actions/**` · update the progress table above at each session boundary · commits
are handed to Taylor to run.
