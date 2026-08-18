# Marketplace menu browsing — plan

_Drafted 2026-08-18. Status: **deliberately not started** — this is a
demand-side growth feature; it gets more valuable with every restaurant on the
platform and demos thin with one or two. Decisions locked at drafting: build
**both** a dedicated crawlable menu page per restaurant and a homepage quick
preview; the order handoff is a **plain redirect** to the storefront (item
deep-linking is a later nicety); **city/cuisine browse pages are a later
phase** of this same plan. (Tracked as todo.md §13; this doc is the spec.)_

## Why this exists (the strategic frame)

Today plateful.fyi's homepage lists public restaurants with client-side search
(`routes/web.php` `home` route → `Welcome.vue`), and each card links straight
off to the restaurant's storefront. A diner can *find* a restaurant on
Plateful but cannot *evaluate* one — the menu lives only on the tenant
storefront, so the marketplace is a directory, not a browsing experience.

The feature: browse any restaurant's menu **without leaving plateful.fyi**,
and only hand the diner to the restaurant's own site once they've decided to
order there.

The restaurant-facing pitch this unlocks — and the reason it belongs in the
sales deck, not just the product: **customers who discover a restaurant
through the Plateful marketplace are still that restaurant's customers, at the
same 4%.** DoorDash charges 15–30% marketplace commission precisely *for*
demand generation, and its Storefront contract says merchants don't own the
customer data. Plateful generates the demand, hands over the customer, and
charges nothing extra for it. This composes with the §4 ownership pitch: the
marketplace fills the Customers page.

**Pricing-promise note (same class as the public $399 cap):** once "no extra
commission for marketplace orders" is said out loud in marketing, charging for
marketplace demand later becomes a broken promise, not a config change. Say it
deliberately.

## What already exists (verified 2026-08-18 — this is mostly reuse)

- `Restaurant::scopePublic()` (status Active + `is_active`) already defines
  marketplace eligibility; the homepage uses it.
- The whole menu serialization pipeline exists:
  `Storefront\MenuController` builds `MenuCategoryData` trees (active
  categories, available items, templates/options) — the read-only page needs
  exactly this query, minus the admin `editor` payload and minus cart wiring.
- `Restaurant::publicUrl()` resolves custom-domain-first — the handoff CTA is
  a one-liner and already respects future custom domains.
- The root domain is not tenant-resolved, so a root route can query any
  restaurant's menu directly (same DB, monolith) without touching tenancy
  middleware.
- Sitemap (`SitemapController`) and the Stories SEO groundwork already exist —
  menu pages slot into the same crawlability strategy.

## Phase 1 — dedicated menu page per restaurant (the SEO backbone)

`plateful.fyi/restaurants/{subdomain}` — a public, read-only menu page on the
root domain. (Prefix deliberately: bare `/{subdomain}` would collide with
`/savings`, `/press`, `/book`, every future root route.)

- Controller (e.g. `MarketplaceRestaurantController`): resolve by `subdomain`
  against `scopePublic()`, 404 otherwise. Reuse the `MenuController` category
  query (extract to a shared query object/service so the two can't drift);
  never include the admin `editor` payload.
- Page: restaurant hero (name, city, hours, description, hero image) + full
  menu with prices and option summaries. **No cart, no add buttons.** One
  persistent CTA: "Order from {name}" → `publicUrl()`. Every item card can
  carry the same link — tapping food should always offer the path to buy it.
- The storefront `Menu.vue` is coupled to cart actions, so decide at build
  time: extract a presentational `MenuBrowser` component both pages share, or
  build a lightweight read-only render. Leaning: lightweight read-only render
  first (the marketplace page wants a *browsing* layout, not a transactional
  one); extract shared components only if they genuinely converge.
- SEO, the actual point of this phase:
  - Server-rendered/crawlable (Inertia v3 SSR, or follow the Stories
    plain-Blade precedent — decide at build time; Stories chose Blade
    *because* complete HTML mattered, and it matters here too).
  - Title/meta: "{Name} menu — {City}, Utah | order direct".
  - JSON-LD `Restaurant` + `Menu` structured data (Google shows menu-rich
    results; almost no independent restaurant has this).
  - Add all public restaurants to `sitemap.xml`.
  - Canonical: self-canonical on the marketplace page. The Inertia storefront
    ships thin first-paint HTML, so the marketplace page is likely the *best*
    crawlable menu each restaurant has — that's a selling point ("being on
    Plateful gets your menu properly indexed"), not a duplicate-content risk.
- Homepage cards link to the menu page (browse) with the storefront link
  (order) still present — browse is the default click, order is the intent
  click.
- Tests: public restaurant renders its menu; non-public 404s; unavailable
  items/inactive categories hidden; no `editor` payload; sitemap entries;
  handoff URL respects custom domains.

## Phase 2 — homepage quick preview

The browsing feel: peek at a menu without even a page navigation.

- "View menu" on each homepage card opens a drawer/modal fetching the same
  serialized menu (lazy — a partial reload or a small JSON endpoint reusing
  the Phase 1 query; never eager-load every menu into the homepage payload).
- Preview shows category names + a sampling of items with prices, with "Full
  menu" → the Phase 1 page and "Order" → the storefront.
- Skeleton state while loading (per the Inertia deferred-props convention).

## Phase 3 — browse/discovery growth (later, on traffic evidence)

City and cuisine landing pages: `plateful.fyi/utah/provo`,
`plateful.fyi/cuisine/pizza` — the same play as the Stories city pieces,
turned into product surface. Each is a crawlable list of matching restaurants
with menu-page links.

- **Blocker to note: there is no cuisine field.** `restaurants` has
  city/state but no cuisine column — the homepage search placeholder already
  promises "cuisine" and actually matches name/city/state/description
  (`Welcome.vue:39-46`). Phase 3 needs a real `cuisine`/tags field (set
  during onboarding or backfilled by the menu-extraction AI, which already
  reads the whole menu). Fix the placeholder honesty gap whenever touching
  this.
- City pages can ship before cuisine pages (data already exists).
- Only worth building with enough restaurants per city that a landing page
  isn't an empty room — same logic as the Customers-page sequencing.

## Later / explicitly out of scope

- **Item deep-linking**: land the diner on the storefront with the tapped
  item's drawer open (storefront `Menu.vue` accepting `?item=`). Phase 1 is a
  plain redirect; add this when funnel data says the extra step loses people.
- **Cross-restaurant search of menu items** ("who has birria near me") —
  powerful and expensive; needs search infrastructure. Not now.
- **Ordering on plateful.fyi itself** — never, by design. The whole pitch is
  that the transaction happens on *their* site with *their* customer
  relationship. The marketplace browses; the storefront sells.

## Sequencing triggers

1. After §0 launch blockers — this is growth surface, not launch surface.
2. Phase 1 becomes worth building around the first handful of live
   restaurants (the SEO value accrues per restaurant and takes months to
   compound — earlier is better *once real menus exist*).
3. Phase 2 with Phase 1 or shortly after (it reuses the same query).
4. Phase 3 on evidence: enough restaurants per city, or search-console data
   showing menu pages pulling city/cuisine queries.
5. Marketing copy ("we send you customers and don't charge for them") goes
   on /for-restaurants when Phase 1 ships — the pricing-promise note above
   applies.
