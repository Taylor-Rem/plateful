# Task: Rebrand Plateful to the new teal + crimson identity and logo

Apply Plateful's new brand identity across the app: a new color palette and a new
logo. This is a **visual rebrand / re-skin** — update colors, the logo, and
component styling for a cohesive look. Do **not** change app behavior, routes,
data, or business logic.

Follow `CLAUDE.md` conventions. This is a Tailwind CSS 4 + Vue 3 + Inertia app;
run the Boost `search-docs` tool for Tailwind v4 theming before editing CSS.

## Brand colors

- **Primary — teal `#069494`** (brand, primary buttons, links, active states).
- **Accent — crimson `#B22222`** (secondary emphasis, highlights, key CTAs).
- Neutrals: keep a clean near-black text / off-white background system (the app
  already uses `#1b1b18` text, `#FDFDFC`/white surfaces, `#0a0a0a` dark bg — keep
  or lightly cool them to complement teal).
- Generate tint/shade ramps (roughly 50–900) for teal and crimson so hovers,
  borders, subtle backgrounds, and focus rings have proper steps — don't use the
  single flat hex everywhere.

**Accessibility (important):** `#069494` is only ~3.2:1 on white, so it passes
WCAG AA for large text / UI fills but **fails for small body text and for white
text on a teal fill**. Use a darkened teal (e.g. ~`#057575` or darker) for text,
small elements, and link text; reserve bright teal for larger fills/accents.
Crimson `#B22222` passes AA (~5.8:1) for text and buttons. Verify contrast on
primary buttons and links in both light and dark mode.

**Semantic colors:** keep success/warning/info distinct from the brand. Note that
error-red is visually close to crimson — either use a clearly different red for
destructive/error states or make crimson-as-error intentional and consistent.

## Where colors live

Define the palette as **Tailwind v4 theme tokens in `resources/css/app.css`**
(the `@theme` / CSS-variable approach — check `search-docs`), then use those
tokens/utilities in components. Replace hardcoded hex values in the Vue files
with the new tokens. The current codebase hardcodes an old orange-red accent that
must be fully removed, including at least: `#f53003`, `#FF4433`, `#e63b2c`,
`#d62a02`, `#d92900`, `#fff2f2`, and related tints. Grep the repo for these and
map them to the new teal/crimson tokens. Don't leave stray old-accent hexes.

## Logo

Replace the mark in `resources/js/components/AppLogoIcon.vue` (find the actual
path if it differs) with the new two-color logo. The master file is
`branding/plateful-logo.svg`. Note it is **two-color** (teal plate, crimson fork,
white rim) — it can no longer be a single `currentColor` icon, so render the real
fills and keep the sizing/`className` prop working. The mark:

```html
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 340" role="img" aria-label="Plateful">
  <circle cx="176" cy="120" r="92" fill="#069494"/>
  <circle cx="176" cy="120" r="70" fill="none" stroke="#ffffff" stroke-width="8"/>
  <path fill="#B22222" d="M52,43.5 A3.5,3.5 0 0 1 59,43.5 L59,92 Q61,99 63,92 L63,43.5 A3.5,3.5 0 0 1 70,43.5 L70,92 Q72,99 74,92 L74,43.5 A3.5,3.5 0 0 1 81,43.5 L81,92 Q83,99 85,92 L85,43.5 A3.5,3.5 0 0 1 92,43.5 L92,104 C92,128 81,130 81,150 L81,296 Q81,310 72,310 Q63,310 63,296 L63,150 C63,130 52,128 52,104 Z"/>
</svg>
```

Also update: the **favicon** in `public/` (generate PNG/ICO from the logo), the
`theme-color` meta in `resources/views/app.blade.php` (set to the teal), and any
OG/social image if one is referenced.

## Surfaces to cover (Plateful's own chrome only)

Apply the rebrand consistently across Plateful-owned surfaces:

- Diner homepage — `resources/js/pages/Welcome.vue`
- Owner marketing — `resources/js/pages/ForRestaurants/Landing.vue` and `Signup.vue`
- Admin console — `resources/js/pages/Admin/**` (layouts, nav, buttons, dashboards)
- Auth pages — `resources/js/pages/auth/**` and `settings/**`
- Platform footer/nav, legal pages, and the base `app.blade.php`

**Do NOT restyle per-restaurant storefronts with the brand colors.** Individual
restaurant storefronts are **tenant-themed** (each restaurant sets its own colors;
see the storefront theming system and `StorefrontThemingTest`). The rebrand is for
Plateful's platform surfaces, not tenant storefront content. Only update
storefront *chrome* that is genuinely Plateful-branded (e.g. a "Powered by
Plateful" element), never the restaurant's configurable theme.

## Verify

- `npm run build` succeeds (Vite needs Node 20.19+).
- `npm run lint && npm run format` clean.
- Run the render/theming tests: `php artisan test --compact` — pay attention to
  `StorefrontThemingTest`, `SeoTest`, and storefront tests; tenant theming must
  still pass unchanged.
- Take before/after screenshots of the homepage, for-restaurants page, admin
  dashboard, and a login page in **both light and dark mode**; confirm no old
  orange-red remains and contrast is legible.
- Confirm a tenant storefront still renders in its own theme, not the platform teal.

## Definition of done

New palette defined as Tailwind v4 tokens in `resources/css/app.css`; all old
orange-red hexes replaced; new two-color logo in `AppLogoIcon.vue`; favicon +
theme-color updated; Plateful platform surfaces consistently teal/crimson in both
modes; tenant storefront theming untouched and tests green; lint/format/build
clean. Summarize what changed. Do not alter dependencies, app behavior, or create
documentation files.
