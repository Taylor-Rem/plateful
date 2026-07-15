# Plateful — Product & Go-to-Market Strategy

_Status: revised draft · Last updated: 2026-07-09_

> **Build-state note (2026-07-15):** this is the *strategy* document; its "code reality" remarks
> and phased roadmap (§7–§9) are frozen at 2026-07-09 and have been overtaken by the build. Since
> then: the per-tenant encrypted credential store (`pos_integrations`), the **Square and Clover
> adapters** (OAuth connect + order push), and **Uber Direct delivery end-to-end** (per-restaurant
> credentials, quote-before-payment, webhooks, auth/capture) have all shipped. For current build
> state always read [todo.md](../todo.md); the strategy and pricing reasoning here still stand.

This document started as a POS-integration brief centered on winning Toast restaurants by
undercutting their fees. Working through the actual economics moved the strategy somewhere
better and more honest. This revision captures where it landed. The short version:

- **We do not compete with Toast, and we can't beat Toast on price.** Our real competitors are
  the delivery marketplaces (DoorDash, Uber Eats) and the direct-ordering platforms (ChowNow,
  Owner.com, Beyond Menu).
- **Our beachhead is the independent restaurant with no online ordering of its own**, dependent
  on DoorDash/Uber, juggling their tablets — not the Toast power-user.
- **Our moat is a near-zero cost structure (unbeatable price) plus local, founder-led,
  high-touch service** in a single geography (Utah), not superior technology.
- **The build splits cleanly into two independent jobs**: getting the order to the kitchen
  (POS injection or a cloud printer) and delivering it (DoorDash Drive / Uber Direct via the
  existing `DeliveryDispatcher`).

All architecture claims are validated against the codebase; "Code reality" callouts mark where
the plan meets what exists.

---

## 1. Strategic summary

Plateful is a branded online-ordering layer that a restaurant owns, sitting on top of whatever
register the restaurant already runs and using Stripe for payments. The pitch to a restaurant:
*"Take orders through your own channel instead of renting your customers from the delivery apps,
own your customers' contact info, and pay a fraction of what the apps charge."*

There are two possible customer segments. We are deliberately focusing on the first and
deprioritizing the second.

1. **Growth segment (our focus).** Independent restaurants with no website / no online ordering
   of their own, taking their online volume through DoorDash and Uber Eats and paying 15–40%
   for it. They have a concrete, quantifiable pain (delivery-app fees) and a visible operational
   mess (a counter full of delivery tablets). We give them their own ordering channel, their
   customer list, and a dramatically lower fee.

2. **Margin / Toast segment (deprioritized — see §3).** Restaurants already running Toast with
   high online volume. Attractive on paper, but we cannot beat Toast's own online-ordering
   economics on price, and Toast's order-injection API is gated. Revisit only after we've won
   the growth segment and can approach Toast from a position of a shipped product.

Core positioning is unchanged and firm: **integrate, don't replace the POS.** We are not a
register, we don't do payments hardware, and we don't try to out-Toast Toast.

---

## 2. What Plateful is today (code-confirmed)

Multi-tenant restaurant-ordering SaaS. **Laravel 13.7 · PHP 8.4 · Vue 3.5 · Inertia v3 ·
TypeScript 5 · PostgreSQL · Stripe Connect.** ~175 PHP classes under `app/`, ~93 Pest test
files. Live at plateful.fyi.

Facts that shape this plan:

- **Tenancy is row-scoped, single-database.** The tenant *is* the `Restaurant` model.
  `Http/Middleware/ResolveTenant.php` resolves a tenant from the host (`custom_domain`, then
  `subdomain`). `Tenancy/BelongsToTenant.php` + `Tenancy/TenantScope.php` add a global
  `restaurant_id` scope and auto-fill it on create.
- **Order flow runs through `Services/OrderPlacement.php`.** `prepare()` builds a serializable
  snapshot (no writes); `materialize()` writes the `Order` in a transaction, **idempotent on
  `stripe_checkout_session_id`**, and emits the first `OrderEvent` and notification emails. This
  is the correct place to fire an order to the kitchen (POS/printer) and to dispatch delivery.
- **Order lifecycle is a validated state machine.** `Enums/OrderStatus.php`
  (`Pending → Confirmed → Preparing → Ready → Completed`, `Cancelled` from any non-terminal
  state), enforced by `Services/OrderTransition.php::apply()`, which writes an `OrderEvent` audit
  row on every transition.
- **Payments are Stripe Connect (Express, direct charges).**
  `Services/Stripe/StripeConnectService.php` creates the Checkout Session as a direct charge on
  the restaurant's connected account with an `application_fee_amount` = Plateful's cut
  (**4% flat of food subtotal** — see §6). Restaurant pays Stripe's
  processing; Plateful takes the application fee on top.

> **Code reality — two patterns already exist that we build on.** The `DeliveryDispatcher`
> pluggable-adapter subsystem (§7) is the template for both delivery and POS injection, and its
> provider enum already lists `DoorDash` and `Uber` (unimplemented). There is **no per-tenant
> encrypted credential store** yet — that's net-new work (§7c).

---

## 3. Who we actually compete with (and why not Toast)

The original brief assumed Toast was the opponent. It isn't. Understanding the layers clarifies
who is:

- **Payment rails (Stripe):** not a competitor — it's our supplier. Stripe's ~2.9% + 30¢ is a
  cost floor we and everyone else pay. We can't "beat" it because we use it.
- **The register (Toast, Square, Clover):** we don't compete here. Toast owns payments +
  hardware + contracts; that's their moat and we stay off it.
- **Demand + delivery (DoorDash, Uber Eats marketplace):** a real competitor for the online
  order — but they bring *new* customers, which we don't. We compete for the restaurant's *own*
  repeat/direct customers.
- **Direct-ordering platforms (ChowNow, Owner.com, Beyond Menu):** **our true head-to-head
  competitors.** They do exactly what Plateful does — commission-free/low-fee branded ordering
  to reduce delivery-app dependence.

### Why we cannot beat Toast on price (structural, not a tuning problem)

A restaurant's online cost on Plateful is **Stripe (2.9% + 30¢) + our fee**. Toast's own online
ordering is **3.5% + 15¢** plus a ~$75/mo add-on and a $0.99 guest fee. Because Stripe's base
rate already ≈ Toast's rate, anything we add on top pushes us *above* Toast on per-order
processing — at 4%, at 3.5%, at basically any fee that leaves us a margin. On total monthly cost
including Toast's $75/mo add-on, Toast online only loses to us below ~45–65 orders/month — i.e.
never, for a real restaurant. So "cheaper than Toast online" is a comparison we lose regardless
of fee. Chasing it just gives away revenue on the orders we *do* win.

**Implication:** don't build for Toast now. Their fee-injection API is gated (application +
certification via the Toast Partner Integrations program), and even if we integrated, we don't
save a Toast restaurant money on their online channel. The Toast restaurant's *only* Plateful
win is peeling their DoorDash/Uber volume onto a cheaper channel + owning their customers — real,
but not enough to lead with, and gated behind slow partner approval. Park it.

### Our two real edges (against ChowNow / Owner.com)

We are entering a crowded, funded category. We win not on technology but on:

1. **A cost structure they can't match.** ChowNow charges ~$119–328/mo; Owner.com ~$249–499/mo,
   because they carry investors, salaried teams, and marketing budgets. Our costs are a few
   dollars a month and we answer to no one. We can be profitable at prices that would bankrupt
   them. Our lack of investors is the moat.
2. **Local, founder-led, high-touch service in one geography.** National platforms are
   impersonal support queues. We can be the local operator who shows up, sets it up for free,
   knows the Utah scene, and answers the phone. SMB restaurateurs buy from people they trust —
   especially locally.

Our bottleneck is therefore **distribution/sales, not product or market.** The playbook is local
hustle + unbeatable price, one restaurant at a time.

---

## 4. Target customer & market size

### Ideal customer profile (ICP)

An independent restaurant that:

- has no website or no online ordering of its own,
- takes its online/delivery volume through DoorDash and/or Uber Eats (paying 15–40%),
- is juggling one or more delivery-app tablets on the counter,
- runs **Square or Clover** (so we can inject cleanly) — or a plain register (we bring a printer),
- is **not** locked into Toast.

The sharpest wedge within this: restaurants on **Square/Clover** that already resent the
delivery-tablet tax. Clean injection + obvious pain = highest-conviction first customers.

### Market size (it is not the constraint)

National funnel: ~730,000 US restaurants → ~70% independent (~510,000) → ~44% of independents
have no website of their own → **~200,000+ in the target**. In Utah (estimated from national
per-capita rates; no exact state count is published): ~7,000 restaurants → ~2,000 in the target.
A thriving solo business needs **100–300 customers.** The pool dwarfs the need. The challenge is
reaching and converting them, not finding them.

---

## 5. The value proposition & fee math

Reframed around the real competitor (the marketplaces), not Toast.

| What the restaurant pays on a $35 order | Effect |
| --- | --- |
| **Uber Eats ~28% commission** | keeps ~72% (~$25.20) — the app keeps the customer |
| **DoorDash ~25% commission** | keeps ~75% (~$26.25) — the app keeps the customer |
| **Plateful @ 4%** (Stripe 2.9%+30¢ + 4%) | keeps ~92% (~$32.29) — **restaurant owns the customer** |
| **Toast online** (3.5%+15¢ + $75/mo + $0.99 guest) | keeps ~96% on processing — but Toast keeps the customer |
| **In-store (Toast register) ~2.49%+15¢** | keeps ~97% |

Read this honestly:

- **Against DoorDash/Uber, we are a blowout** — ~$5–7 back per order, plus the customer's
  contact info. This is the entire pitch and it's a strong one.
- **Against Toast online, we are slightly *more* expensive per order** and don't try to hide it.
  Our win there is killing the $75/mo add-on and the guest fee, and customer ownership — not
  processing price. (This is why we don't target Toast; see §3.)
- **We do not bring new customers.** DoorDash's commission buys discovery we don't provide. The
  honest move is capturing the restaurant's *own* repeat/direct customers, not claiming to
  replace DoorDash's demand engine.

An editable model of all of this lives at `docs/plateful_fee_comparison.xlsx` — change the fee
cell and every number recalculates.

---

## 6. Pricing model

### The decision

- **Percentage fee, flat rate: 4%** of the food subtotal (on top of Stripe). Locked 2026-07-10:
  no tiers, no volume discounts, no floor. Tips and tax are excluded from the fee.
  - **Base pinned 2026-07-15: 4% of the *post-redemption* food subtotal** — "we take 4% of what the
    customer actually pays for food." Tax, tip and delivery excluded. (Roadmap §1 / §10.)
  - **Sales guidance — do NOT pitch "we don't take a cut of tips" as a differentiator.** Verified
    2026-07-15: every commission-type fee in this market already excludes tips. DoorDash's own
    glossary defines subtotal as "before taxes, commissions, fees, error charges, and tips";
    ChowNow's Restaurant Agreement says "excluding Fees, taxes, tip, delivery fees." We match the
    norm. Claiming it as an edge invites an easy rebuttal. **The edge is 4% vs 15–30%.**
  - **Never claim tips are fee-free.** The restaurant still pays Stripe's 2.9% + 30¢ on the gross
    charge, tip included — as they would with Square ("including tax and tip") or Toast ("the gross
    amount of all card transactions"). The card rails can't split a tip from a sale. Tips are free of
    *our* cut, not of Stripe's. An operator who has read their Toast statement will catch this.
  - **Two claims that ARE real and defensible:**
    1. *"We charge on what you collect, not what you would have."* Uber applies its fee to "order
       sub-totals **before discounts**"; ours follows the discounted subtotal, so we absorb 4% of
       every loyalty redemption and the restaurant funds 96%.
    2. *"Rewards are yours."* Restaurants opt in or out, set their own earn rate, and fund their own
       redemptions (§10) — the marketplaces don't offer that at all.
- **Free setup / onboarding** — part of the pitch, and a differentiator vs. competitors who
  charge setup fees ($119–499 at ChowNow).
- **Paid custom/ongoing support** for special work, extra training, and bespoke requests —
  *but fixing our own bugs is always free.*
- **Per-order minimum: deferred.** An on-top 4% is always positive, so no floor is needed to stay
  solvent. Revisit only if tiny-order economics prove annoying.

### Why this shape (the reasoning we worked through)

- **Percentage, not flat per-order.** A flat per-order fee (e.g. 49¢) gives away almost all
  revenue on large tickets (49¢ vs $2.50 on a $50 order) and is *disproportionately expensive on
  small orders* (49¢ = 8% of a $6 order), which would discourage exactly the small-order volume
  we want restaurants to push through us. A percentage scales down gracefully on small orders and
  up on large ones — it's the more *aligned* model.
- **Flat percentage, not tiers.** Volume tiers create a cliff — right below a threshold, one more
  order jumps the price — giving the restaurant a reason to hold volume down. A constant "4% every
  order" has a tiny, known, constant disincentive that never changes behavior.
- **Incentive alignment is the core principle.** A flat percentage keeps us on the same side as
  the restaurant: they want to push *every* order through us. This is what makes the "own your
  channel" pitch internally consistent.
- **Charge for attention separately from orders.** Our scarce cost is *time/support*, which
  tracks neediness, not order count. A percentage can't recoup that (it charges the smooth whale
  more and the needy small shop less). So price time directly: free setup + free bug-fixing +
  paid custom work. The optional per-order minimum covers the "low-volume client who still needs
  support" case.
- **Don't chase "cheaper than Toast."** No fee level wins that (§3). 3.5% is priced to win a
  comparison that can't be won; it just leaves money on the table on the DoorDash/Uber orders
  where we win big anyway. Hence a flat 4%, not 3.5%.

### Pricing vs. competitors

At 4% on a restaurant doing ~1,200 online orders/mo at $35, Plateful earns ~$1,680/mo while
saving the restaurant ~$3,000+/mo vs. their current DoorDash/Uber mix. Against ChowNow
($119–328/mo) and Owner.com ($249–499/mo), we can undercut on price *and* offer free setup,
because our cost structure allows it.

---

## 7. The build — two independent jobs

The key technical insight: **"get the order to the kitchen" and "deliver the order" are separate
problems with separate solutions.** Splitting them collapses what looked like a four-case matrix
into two clean dimensions.

| Restaurant setup | Order receiving (kitchen sees ticket) | Delivery (only if not pickup) |
| --- | --- | --- |
| Register only, no tablets | Plateful cloud **printer** or Plateful tablet | DoorDash Drive / Uber Direct |
| Register only, has DD/Uber tablets | Same — their delivery tablet is closed to us | DoorDash Drive / Uber Direct |
| Square or Clover (tablets or not) | **Inject into their POS** (no new device) | DoorDash Drive / Uber Direct |

The only variable that matters for order receiving is **Square/Clover vs. plain register.** The
delivery-app tablets are irrelevant — they're DoorDash's/Uber's closed devices and we cannot push
orders onto them.

### 7a. Order receiving

**POS injection (Square/Clover).** Mirror the `DeliveryDispatcher` adapter pattern:
- `app/Contracts/PosProvider.php` — interface: `name()`, `supports(Restaurant)`,
  `pushOrder(Order): PosPushResult`, `syncMenu()` (later), token/auth handling.
- `app/Services/Pos/PosDispatcher.php` — resolves the tenant's one POS adapter from an injected
  keyed map, calls `pushOrder()`, records the result, drives the failure policy (§7d).
- `app/Enums/PosProviderName.php` — `Square`, `Clover`, (`Toast` later).
- Concrete adapters `SquarePosProvider`, then `CloverPosProvider`, registered in
  `AppServiceProvider::register()` exactly like the delivery dispatcher.
- `OrderPlacement` stays POS-agnostic — it calls the dispatcher.

**Cloud printer (register-only).** For restaurants with no smart POS, the elegant answer is *not*
another tablet to babysit — it's a **cloud receipt printer** (Star CloudPRNT, Epson): the order
just prints in the kitchen, no screen to watch, no taps to accept. Pitch: "no tablet — your
orders just print next to the others," which is genuinely *less* to manage than their DoorDash
tablet. A Plateful tablet/app is the fallback for restaurants that want an on-screen order view.

### 7b. Delivery (this is what DoorDash's API is actually for)

Two different DoorDash products — don't confuse them:
- **DoorDash Marketplace** (app + tablet): 15–30% commission, because DoorDash brings the
  customer *and* delivers. Expensive. Closed to us.
- **DoorDash Drive** (API): delivery-*only*. The order originates on Plateful; we hire a DoorDash
  driver for a **flat fee** (~$7–8, negotiable), no commission — because we supply the demand and
  only pay for the driver. **Uber Direct** is the identical Uber product.

This is exactly how ChowNow/Owner.com offer "~$7.98 delivery." Drive/Direct are far more
accessible to a solo dev than Toast's gated program (direct API signup).

> **Code reality — this maps onto an abstraction that already exists.** The `DeliveryDispatcher`
> (`app/Services/Delivery/DeliveryDispatcher.php`), its `DeliveryProvider` contract
> (`app/Contracts/DeliveryProvider.php`), and the `DoorDash`/`Uber` entries already in
> `DeliveryProviderName` are the extension points. `DoorDashDriveProvider` and
> `UberDirectProvider` are the adapters that fill them. Only `SelfDeliveryProvider` is
> implemented today, so these are first real fills — budget for webhook/sandbox unknowns.

### 7c. Per-tenant encrypted credential store (net-new — build first)

No per-tenant OAuth/API-key vault exists today. Establish one before any adapter:
- A `pos_integrations` table (tenant-scoped via `BelongsToTenant`) with `provider`,
  `external_merchant_id`/`location_id`, `access_token`, `refresh_token`, `token_expires_at`,
  `status`, `scopes`.
- `encrypted` casts (or `Crypt::`) on token columns — the app's first such store; set the
  precedent cleanly. Delivery credentials (Drive/Direct API keys) can share this store.
- Token-refresh path (Square/Clover/DoorDash/Uber all use expiring tokens).
- A tenant-facing "Connect your POS / delivery" OAuth flow in admin, modeled on the existing
  Stripe Connect onboarding.

### 7d. Injection trigger, idempotency, failure handling

- **Trigger** from `OrderPlacement::materialize()` after the order commits and payment confirms —
  the same point that emits the first `OrderEvent`. Push asynchronously (queued job) so a slow POS
  never blocks checkout.
- **Idempotency:** reuse the existing `stripe_checkout_session_id` idempotency pattern; send an
  idempotency key to the POS/printer and record the returned ticket id.
- **Failure modes, all explicit and loud** (a paid order must never silently vanish): POS/printer
  down → queue retries with backoff, then alert staff; token expired → refresh, else flag for
  reconnection; item-mapping mismatch → fall back to a plain line item with a text note (§8).
- **Observability:** record every attempt as an `OrderEvent`.

---

## 8. Menu / item mapping (the hidden hard part, for POS injection)

Relevant to Square/Clover injection. Plateful models modifiers as a **reusable template system**
(`ItemTemplate → ItemTemplateGroup → ItemTemplateOption`, with `price_delta_cents`,
`min_selections`/`max_selections`), which does **not** map 1:1 onto POS *per-item* modifier lists.
So each pushed order must reference POS catalog object ids the kitchen recognizes.

Approach:
- **v1: reference-map, don't sync.** Store a per-tenant external-id map
  (`plateful_menu_item_id → pos_catalog_item_id`, option → pos modifier id), populated by a guided
  one-time matcher in admin (fetch the POS catalog, auto-match by name, staff confirms).
- **Unmatched items are a handled state, not a crash.** Fall back to a plain line item + text note
  so the ticket still fires. Never drop a line.
- **Menu drift** is ongoing; v1 tolerates it via the text fallback; real two-way menu sync is a
  later phase.
- **Pricing authority:** Plateful remains the source of truth for the price the customer paid; the
  POS/printer ticket is for fulfillment, not re-pricing.

---

## 9. Phased roadmap

| Phase | Build | Unlocks | Notable risk |
| --- | --- | --- | --- |
| **0 — Foundations** | `PosProvider` contract + `PosDispatcher`; per-tenant encrypted `pos_integrations` store; async push trigger + failure states in `OrderPlacement`; item-mapping table + admin matcher | Internal enabler | Getting credential/idempotency/failure primitives right |
| **1 — Square injection** | `SquarePosProvider` (OAuth, `pushOrder`, catalog fetch) | Square restaurants — clean injection, our sharpest wedge | First real third-party adapter |
| **1b — Cloud printer** | Printer integration (Star/Epson CloudPRNT) | Register-only restaurants (no smart POS) | Hardware/printer provisioning + support |
| **2 — Delivery dispatch** | `DoorDashDriveProvider` + `UberDirectProvider` in the existing `DeliveryDispatcher` | Delivery (not just pickup) for all of the above | Drive/Direct onboarding, webhooks |
| **3 — Clover injection** | `CloverPosProvider` | Clover restaurants | Clover app-market review |
| **Later — Toast / depth** | `ToastPosProvider` + partner application; menu sync; loyalty/marketing modules | Toast restaurants (low priority); retention | Gated Toast approval; menu-drift reconciliation |

Phases 1, 1b, and 2 can partly parallelize — order receiving and delivery are independent. The
first sellable milestone is **Square injection + pickup**; add delivery (Phase 2) and the printer
(Phase 1b) to widen the addressable set.

---

## 10. Competitive landscape

| Player | Model | Price | Notes |
| --- | --- | --- | --- |
| **ChowNow** | Flat monthly, 0% commission + 2.95%+29¢ processing | ~$119–328/mo + setup | Category leader, funded, strong support (4.6 G2), site + app + marketplace |
| **Owner.com** | Flat monthly or low base + 5%/order | $249/mo (+5%) or $499/mo | VC-backed, aggressive; AI site + SEO + app + SMS/email. "Own your customers" is their pitch |
| **Beyond Menu** | First-party ordering, low fees | Cheaper, less polished (3.9 G2) | Tens of thousands of restaurants, budget option |
| **Plateful (us)** | 4% flat, free setup | Undercuts all of the above | Solo, local, owns its code |

The category is proven (demand exists) but contested. We differentiate on **price** (structural
cost advantage) and **locality/high-touch** (Utah, founder-led), not features. We must reach
rough feature parity on what matters — branded ordering, clean POS injection / printing, delivery
dispatch, and eventually the customer-remarketing payoff (email/SMS) that makes "own your
customers" real.

---

## 11. Go-to-market

- **Beachhead: Utah, independents, Square/Clover, tablet-juggling, non-Toast.** Start with the
  restaurants whose pain is visible and whose injection is clean.
- **Sell locally and by hand.** Founder-led, in-person, free setup, personal support. This is the
  one arena where a solo founder beats funded national platforms.
- **Lead with the two things we actually win on:** fee savings vs. DoorDash/Uber, and customer
  ownership. Do not pitch "cheaper than Toast."
- **Only need 100–300 customers.** Frame effort accordingly — this is a hand-to-hand distribution
  game, not a scale game.

---

## 12. Non-goals (explicit)

- **Not becoming a POS / register.** No EMV hardware, no card-present terminals, no cash drawers,
  no KDS. We ride Stripe deliberately.
- **Not chasing Toast restaurants now.** We can't save them money on their online channel and
  their API is gated. Revisit post-beachhead.
- **Not competing on being the cheapest raw processor.** Stripe is; we sit on top of it.
- **Not a demand marketplace.** We don't bring new customers — that's DoorDash's job. We monetize
  the restaurant's *own* customers.

---

## 13. Immediate next steps

1. **Pricing — LOCKED (2026-07-10): 4% flat** of food subtotal, free setup, paid custom support,
   per-order minimum deferred. Implemented as the Stripe Connect application fee (see §6).
2. **Validate with 3–5 Utah restaurants:** collect real DoorDash/Uber statements, run
   `docs/plateful_fee_comparison.xlsx`, confirm the savings story and that they're on Square/Clover.
3. **Spec Phase 0** (PosProvider interface, `pos_integrations` encrypted store, push trigger,
   failure-state machine, item-mapping) reviewed against the `DeliveryDispatcher` pattern.
4. **Build the Square injection + pickup MVP**, then add DoorDash Drive / Uber Direct delivery and
   the cloud-printer path.
5. **Do not** start Toast work or the Toast partner application until the growth-segment beachhead
   is proven.

---

## Sources (verified July 2026)

- [US restaurant counts — CKitchen 2026](https://www.ckitchen.com/blog/2026/2/how-many-restaurants-are-in-the-u-s-2026.html)
- [Independents without websites — MapsLeadExtractor](https://mapsleadextractor.com/industries/restaurants-no-website-usa)
- [Delivery market share — OysterLink](https://oysterlink.com/spotlight/food-delivery-market-share-statistics/)
- [Third-party delivery fees 2026 — Rezku](https://rezku.com/blog/third-party-delivery-fees-in-2026-what-doordash-uber-eats-grubhub-really-cost-restaurants/)
- [Toast fees 2026 — Merchant Insiders](https://merchantinsiders.com/blogs/toast-fees/)
- [Toast requires its own processing — Merchant Cost Consulting](https://merchantcostconsulting.com/lower-credit-card-processing-fees/toast-review/)
- [Stripe pricing — official](https://stripe.com/pricing)
- [ChowNow pricing — Sauce](https://www.getsauce.com/post/chownow-pricing-and-fees)
- [Owner.com pricing — Sauce](https://www.getsauce.com/post/owner-com-pricing-fees)
- [Beyond Menu vs ChowNow — G2](https://www.g2.com/compare/beyond-menu-for-restaurants-vs-chownow-for-restaurants)
