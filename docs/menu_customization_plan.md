# Menu customization — ingredients, swaps, extras, and the owner wizard

_Drafted 2026-09-10 after the testaurant walkthrough. Status: **planning — not
started.** Locked at drafting (Taylor, 2026-09-10): the analyzer **may suggest
customizations the menu does not print**, clearly labeled and off until the
owner opts in; ingredient authoring lives in **both** storefront edit mode and
the admin; pricing is **per ingredient with bulk shortcuts**._

## Why this exists

Today a customer who wants the Classic Italian without mortadella, or with
extra provolone, has to type it into special instructions. Plateful has a
template builder that can express those choices, but nothing on testaurant
uses it that way, and the menu analyzer never proposes it. The reason is a
rule in `MenuExtractionService` (line ~31): *only capture options the menu
prints; never invent customizations.* That rule protected the wrong thing.
The risk was never the analyzer *proposing* "extra cheese" — it was an
unconfirmed choice reaching a customer. So the guardrail moves from
extraction to **confirmation**: the analyzer is generous, the owner says yes
to each thing, and the depth lives in that yes step. The wizard itself is how
owners learn the platform can do this at all.

The product goal in one line: **when an item is added or a menu is imported,
ask the owner which ingredients customers can leave out, add extra of, or
swap, and what that costs — in plain language, with sensible defaults, and
in bulk.**

## What already exists (verified 2026-09-10 — the runtime mostly carries over)

- **Templates → groups → options** (`item_templates`, `item_template_groups`
  with min/max, `item_template_options` with `price_delta_cents` and
  `is_available`). One template per item via `menu_items.item_template_id`;
  per-item defaults via `menu_item_default_selections`. Admin CRUD at
  `Admin/TenantAdmin/Templates/*` (`TemplateForm.vue`, 396 lines).
- **The configurator** (`ItemConfiguratorModal.vue`) renders groups as
  radio/checkbox lists, prices deltas *relative to the item's defaults*
  (deselecting a $0 default costs $0), enforces min/max, and now supports
  edit-in-place from the cart (2026-09-10).
- **Runtime paths that speak "option ids in groups"** — all of them must
  keep working unchanged or be touched once:
  `CartManager::validatedTemplateFor()` + `buildModifiersSnapshot()`,
  `CartManager::signatureFor()` (merge key), `MenuItem::priceForSelectionsCents()`,
  `OrderPlacement` integrity re-check (lines ~505–600: option still exists,
  still available, group min/max, price unchanged), `CartItemData` /
  `OrderItemData` summaries, `Kitchen.vue`, `Orders/Show.vue`,
  `OrderConfirmation.vue`, and the POS text notes
  (`CloverPosProvider::lineNote()`, `SquarePosProvider::modifierNote()` —
  both just list selected option names, so new shapes only need to render
  into that note).
- **The import pipeline**: `MenuImportController` → `ExtractMenuJob` →
  `MenuExtractionService` (tool-use JSON schema: categories/items/
  `option_sets`) → `ExtractedMenuSanitizer` (caps from `config/menu_import.php`)
  → `MenuImportReview.vue` (745 lines; light editing of items and option sets)
  → `MenuImportConfirmRequest` → `MenuBuilder::buildFromImport()` (option sets
  become templates, `is_default` options become item defaults). Re-import
  replaces categories/items transactionally.
- **Item authoring** lives on the storefront in edit mode
  (`MenuItemEditDrawer.vue`: name, description, category, one template,
  price, availability, featured, image, default selections). The admin Menu
  page is categories + import + a link to Templates; it does not edit items.
- **Testaurant on dev** (The Rose PDF import, 2026-07-27, re-imported after
  option sets shipped): five templates, all printed choices. The single-slot
  limit already shows: "Add salad/soup" is duplicated into four templates
  because an item can only hold one.

## The model (the part to get right first)

Ingredients are **per item**; choices like size are **reusable**. Keep both,
and let an item carry both.

1. **`menu_item_ingredients`** — the owner-facing source of truth. One row per
   ingredient: `menu_item_id`, `name`, `position`, `is_removable` (bool),
   `extra_price_cents` (nullable — null means "no extra offered"),
   `swap_template_id` (nullable FK → `item_templates`, see 3), `is_locked`
   (shown in the list but not changeable — "the bun"). Extracted from printed
   descriptions on import, split from the description on manual add, or typed.
2. **Item-owned groups.** `item_template_groups.item_template_id` becomes
   nullable and gains `menu_item_id` (nullable) + `kind`
   (`choice` | `included` | `extras` | `swap`). Options gain
   `menu_item_ingredient_id` (nullable) + `kind` so generated rows are
   **upserted by (ingredient, kind), never recreated** — option ids stay
   stable across saves, so existing cart lines don't fail the integrity check
   every time the owner tweaks a price.
3. **Swap sets are just reusable single-group templates.** "Cheeses",
   "Sauces", "Breads": a template with one single-select group. Attaching a
   swap set to an ingredient attaches that template to the item and sets the
   item's default to the printed ingredient (creating the option in the set
   if it's missing). Defaults are already per item, so provolone is the
   default on the Classic Italian and mozzarella on the next sandwich with
   no new tables.
4. **Many templates per item.** `menu_item_templates` pivot
   (`menu_item_id`, `item_template_id`, `position`), backfilled from
   `item_template_id`, then the column dropped. "Sandwich size" stays shared;
   the item adds its own ingredient groups next to it. Fixes the duplication
   above for free.
5. **The compiler** (`Support/Menus/IngredientGroupCompiler`, pure, unit
   tested) turns an item's ingredient rows into its item-owned groups:
   - every removable, non-swappable ingredient → an option in the item's
     **`included`** group (min 0, no max, $0, **default-selected**; locked
     ingredients are listed disabled). Unchecking = leave it out.
   - every ingredient with `extra_price_cents` → "Extra {name} (+$x)" in the
     **`extras`** group (min 0, no max).
   - every ingredient with a swap set → the set's template attached (kind
     `swap`), default = the ingredient; `is_removable` makes that group
     min 0 (the configurator already renders a "None" radio for optional
     single-select).
6. **Snapshot v2 — record deviations, not just selections.** _(Built
   2026-09-10.)_ `buildModifiersSnapshot()` adds `is_default` to each
   selection, `single_select` per group, and a `removed: [{option_id,
   option_name}]` list per group (defaults the customer deselected,
   including "None" on a swap). Every renderer — cart summary, order
   summary, kitchen, confirmation, emails, both POS notes — goes through
   `Support/Menus/ModifierSummary` with one rule set: default selections in
   an **included** group are hidden (they are the item as printed); every
   other group lists its picks; a turned-off default renders as **"No X"**,
   except in a pick-one group that already has a pick ("Large", never
   "No Medium · Large"). Result: `12" · No mortadella · Extra provolone`,
   and a swapped topping reads `Bacon · No Pepperoni`, which the kitchen
   needs. Legacy snapshots (no flags) render exactly as before.
7. **Everything else stays.** Signature, price calc, integrity check, and
   validation keep operating on option ids; they just read groups from
   `MenuItem::optionGroups()` (all attached templates' groups + item-owned
   groups, ordered) instead of `$item->template->groups`. `MenuItemData`
   gains `groups` (flattened, what the configurator renders) and
   `templateIds`; `template` goes away.

**Why not virtual ids?** Generating "ing:12:remove" ids on the fly would put a
second id space into every runtime path above. Materializing options keeps
the runtime single-shaped; the cost is the upsert discipline in 2.

## The owner experience

**Ingredients panel** (one Vue component, used in the storefront item drawer
and on the admin Menu page's item rows). No min/max anywhere. Each row:

```
[Mortadella      ]  ☑ Can leave out   Extra: [$1.50]   Swap with: [Meats ▾]   ⋮
```

- "Split from description" fills the list from the printed description
  (client-side, on commas/"and"/"with"; no API cost). Rows can be renamed,
  reordered, merged, deleted.
- "Suggest customizations" (per item, ~cents) asks Claude for extras/swaps
  that are common for this kind of item; results land as **suggested** rows,
  switched off, with a "Suggested by Plateful" tag until the owner accepts.
- **Bulk**: "Apply to every item in Sandwiches" copies removable/extra/swap
  settings by ingredient *name* across the category; "Price shortcuts" set
  one extra price for an ingredient name everywhere it appears ("extra
  cheese +$1 on all sandwiches"), and a per-category default for "any extra
  protein" / "any extra cheese".
- Swap sets: pick an existing one, or "+ New set" opens an inline mini-form
  (name + options with deltas) that creates the single-group template
  without leaving the item.
- Saving compiles the groups (5) and previews the customer-facing
  configurator in a side panel so the owner sees exactly what customers see.

**Import wizard** (new step in `MenuImportReview.vue` after items, before
confirm): category by category, "Which of these can customers change?" with
the same rows pre-filled — printed ingredients **removable on**, extras
**off**, swaps **none**, Plateful suggestions **off**. Bulk applies live
here. Skippable per category ("Do this later") so a 400-item import isn't
blocked on it. Confirm builds ingredients + compiled groups through
`MenuBuilder`.

**New item by hand**: after the name/description are saved, the drawer's
Ingredients panel opens with "Split from description" already run.

## The analyzer

`MenuExtractionService` schema and prompt gain:

- `items[].ingredients: string[]` — the printed ingredient list, parsed from
  the description; empty when the menu prints none. Facts, same rigor as
  prices.
- `items[].suggested_customizations: [{name, kind: extra|swap|remove,
  swap_options?: string[], reason}]` — **suggestions**, allowed to be
  unprinted, drawn from what's normal for the dish and cuisine (extra cheese
  on pizza, sauce choice, crust style, protein swap on a bowl). The prompt
  says plainly that these are proposals the owner will confirm, never
  prices — `price_cents` is always null on a suggestion.
- Sanitizer caps: ingredients ≤ 25/item, suggestions ≤ 8/item; names ≤ 60.
- Cost: output grows; expect ~$0.11 → ~$0.15–0.20 per menu.

The "never invent" rule stays for **items, prices, and printed choices**. It
is removed for suggestions, because suggestions are labeled and gated.

## Phases

- **Phase 1 — model + runtime** — **DONE 2026-09-10** (one session; the
  storefront item drawer also moved to multi-template checkboxes so nothing
  owner-facing regressed; `is_locked` dropped as redundant with
  `is_removable`; `menu_item_templates.menu_item_ingredient_id` records
  which swap sets an ingredient attached). Verified on testaurant dev: Classic
  Italian seeded by hand, cart line reads `12" · No Mortadella · Extra
  Provolone cheese` at $16.25, edit-in-place pre-fills the compiled groups.
  Original scope: migrations (1–4), compiler
  (5) + unit tests, snapshot v2 + deviation rendering everywhere (6),
  `optionGroups()` and the DTO change (7), configurator renders the three
  kinds with proper headings ("Leave anything out?", "Add extras",
  "Cheese"), `MenuBuilder` demo/italian preset and `make:restaurant`
  updated, existing tests green (`MenuItemTemplateTest`,
  `MenuItemPriceCalculationTest`, `PlaceOrderModifierIntegrityTest`,
  `CartTest`, `ItemTemplateCrudTest`, `MenuImportTest`). Nothing owner-facing
  yet; testaurant gets a hand-seeded Classic Italian to prove the ticket
  reads right end to end (cart → kitchen → Clover sandbox note).
- **Phase 2 — authoring** — **DONE 2026-09-10** (one session). One
  `components/menu/IngredientsPanel.vue` used by the storefront item drawer
  and an Ingredients dialog on the admin Menu page; "Split from
  description" (client-side, `splitIngredients.ts`); inline swap-set create
  (`POST …/swap-sets`, flashes `createdSwapSetId` so the row auto-selects
  it); "Apply to all in {category}" by ingredient name (`POST
  …/categories/{category}/ingredient-rules`, rows carry the extra price so
  this is the price shortcut too); "Preview as customer" opens the real
  configurator in a no-cart `preview` mode on both hosts; a just-created
  item reopens on its Ingredients step pre-split (`createdMenuItemId`
  flash). Routes exist on both hosts through one controller
  (`*InConsole` variants take the `{restaurant}` param positionally). Three
  Playwright tests cover admin dialog, preview, and the storefront drawer;
  those needed `lib/relativeUrl.ts` because Wayfinder bakes the admin
  domain into absolute URLs. Not built: per-type shortcuts ("any extra
  protein +$3") — there is no ingredient type yet; the category-wide
  by-name apply covers the real case. Original scope: the Ingredients
  panel component, swap-set inline create, bulk + price shortcuts,
  storefront drawer + admin Menu integration, configurator preview, form
  requests + policies, browser tests.
- **Phase 3 — analyzer + wizard** (~2): schema/prompt/sanitizer changes,
  the review wizard step, confirm → `MenuBuilder`, "Split from description"
  and "Suggest customizations" on manual items, re-import carry-over (below).
- **Phase 4 — rollout + polish** (~1): re-import The Rose PDF on testaurant
  **dev**, walk the wizard, then the same on **live** (order history keeps
  snapshots; carts cascade); kitchen/confirmation/email copy; §2b note that
  POS catalog matching now has per-item modifier lists to map (the impedance
  mismatch shrinks, it doesn't vanish).

## Open questions (⚑ = decide before the phase that needs it)

- ⚑ P1 — **"Extra" quantity**: v1 is a single toggle (one "Extra provolone");
  "double extra" would be a quantity on the option. Defer unless a real
  restaurant asks.
- ⚑ P1 — **Half/half** (pizza halves) is out of scope; note it in the
  configurator plan as a later `scope: half` on selections.
- ⚑ P3 — **Re-import carry-over**: re-import replaces items today. Carry
  ingredient settings over by item name (case-insensitive) so a re-import
  doesn't erase an hour of wizard work; surface "3 items lost their
  customizations" in the review when names don't match.
- ⚑ P3 — **Suggestion source**: Claude per import (chosen) vs a curated
  per-cuisine list. Start with Claude; if suggestions are noisy, add the
  curated list as a filter, not a replacement.
- P2 — **Locked ingredients**: shown disabled in the included list (chosen
  above) vs hidden. Shown, so the list doubles as "what's in it".
- P4 — **POS**: text notes carry deviations fine; the guided catalog matcher
  (§2b) should map item-owned groups to per-item POS modifier lists when it
  is built.

## Testaurant, concretely

Until Phase 2 ships there is a no-code path: in the Templates admin create
"Classic Italian" with a Size group (7"/12"), a "Leave out" group (min 0, no
max) of $0 options named "No mortadella", "No mayo", …, and an "Extras" group
with priced options; then switch the item's template in storefront edit mode
and tick 7" as default. Eight sandwiches → eight templates, same clicks on
dev and on live. It works with today's ticket rendering because removals are
explicit selections. It is also exactly the tedium this plan removes.
