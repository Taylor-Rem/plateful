# DoorDash Drive — certification readiness audit

_Audited 2026-07-31 against DoorDash's published integration requirements and the production-access
process. Companion to [doordash-drive-implementation-plan.md](doordash-drive-implementation-plan.md)
(Session 0/6). Sources at the bottom._

**Verdict: the adapter is production-shaped, the UI is not.** Sessions 1–5 built a correct money
model and a correct API client. What no session built is the part the certification demo actually
looks at hardest — **what the customer and the restaurant can see about a delivery in flight.**
DoorDash's requirements name specific facts that must be displayed to each party. Today Plateful
stores several of them and displays none of them.

Nothing here is a redesign. It is one DTO, one migration, two payload fields, and three UI panels.

---

## Scoreboard

| # | Gap | Severity | Reviewed at demo as | Status |
|---|---|---|---|---|
| G1 | No customer-facing delivery tracking at all | **Blocker** | Customer UI | ✅ Closed 2026-07-31 |
| G2 | `support_reference` never captured | **Blocker** | Merchant UI / support flow | ✅ Closed 2026-07-31 |
| G3 | No merchant-facing delivery panel | **Blocker** | Merchant UI | ✅ Closed 2026-07-31 |
| G4 | No restricted-items safeguard | **Blocker** | Compliance | ✅ Closed 2026-07-31 (attestation + guard) |
| G5 | No `pickup_time` on the quote | High | API logs / required fields | ✅ Closed 2026-07-31 |
| G6 | No `items` / `order_value` on the quote | High | API logs / recommended fields | ✅ Closed 2026-07-31 |
| G7 | Webhook signature scheme assumed | Medium | Webhook ingestion | ⏳ Needs portal access |
| G8 | Cancel-response fee field assumed | Medium | Cancellation workflow | ⏳ Needs portal access |
| G9 | Quote-failure copy unrehearsed | Low | Error handling | ✅ Verified 2026-07-31 (copy was already a human sentence) |

**Closure notes (2026-07-31):**
- G1+G3: `App\Data\DeliveryAssignmentData` exposed as `order.delivery` on `OrderData`; tracking card on
  `Storefront/OrderConfirmation.vue` (serves confirmation + account order detail, polls via `usePoll`
  while in flight, shows restaurant phone) and delivery panel on `Admin/TenantAdmin/Orders/Show.vue`
  (support reference, delivery ID, courier, ETAs, tracking link, also polling). The raw DoorDash status
  (`provider_status` column) is the display text, per the status-map note below.
- G2: `support_reference` + `provider_status` columns on `delivery_assignments`; captured in
  `create()`/`status()`/webhook.
- G4: attestation (`restaurants.restricted_items_attested_at`, required to enable delivery, stamped once)
  + `RestrictedItemsGuard` keyword screen — blocks the checkout delivery quote (422 before payment) and
  blocks dispatch (permanent, no retries) for third-party chains; self-delivery exempt.
- G5+G6: `pickup_time` (now + prep_time_minutes, UTC Zulu), `items`, `order_value` on the quote payload.

---

## G1 — No customer-facing delivery tracking · BLOCKER

DoorDash requires the **customer** be shown: a tracking URL, the latest delivery status, the order
dropoff time, the order ID, and **the restaurant's phone number**.

What Plateful shows the customer today, on `resources/js/pages/Storefront/OrderConfirmation.vue`:
the order number, the **order** status (the kitchen lifecycle — `pending`/`preparing`/`ready`, not
the delivery status), the *customer's own* phone, the delivery address, and the money lines.

- `tracking_url` **is** captured — `DoorDashProvider::create()` writes it
  (`DoorDashProvider.php:148`) and `status()` refreshes it (`:170`).
- It is read by **nothing** in `resources/js`. A repo-wide grep for `tracking_url` / `trackingUrl`
  in the frontend returns zero hits.
- The reason is upstream: **`App\Data\OrderData` carries no delivery-assignment fields at all**
  (`app/Data/OrderData.php:13-35`) — no tracking URL, no delivery status, no courier ETA, no driver
  name, no restaurant phone. The data never leaves PHP.

So a customer who pays for delivery sees a static receipt. There is no way for them to know a
Dasher was assigned, where the food is, or who to call.

**Fix:** a `DeliveryAssignmentData` DTO exposed on `OrderData`, plus a tracking card on
`OrderConfirmation.vue` and on the account order-detail page. Include the restaurant's phone (it is
already on the `Restaurant` model and already sent to DoorDash at
`DoorDashProvider.php:278`). `Orders/Index.vue` already uses `usePoll`, so live status refresh is a
solved pattern in this codebase.

## G2 — `support_reference` never captured · BLOCKER

DoorDash returns `support_reference` on accept. It is the ID **their support desk keys on**, and it
is on the required merchant-communications list. Repo-wide it exists in exactly two places:

- `tests/Feature/Delivery/DoorDashProviderTest.php:73` — as a fixture value in a faked response.
- `docs/doordash-drive-implementation-plan.md:171` — as a note that we "may need small UI additions
  to surface `support_reference`/tracking."

`DoorDashProvider::create()` (`:139-152`) does not read it, `delivery_assignments` has no column
for it, and nothing displays it. **The test asserts against a field we throw away.**

Practical consequence beyond certification: when a delivery goes wrong on a real order, the
restaurant calls DoorDash support and cannot give them the reference number.

**Fix:** migration adds `support_reference` to `delivery_assignments`; read it in `create()` and
`status()` alongside `tracking_url`; surface it in the admin delivery panel (G3).

## G3 — No merchant-facing delivery panel · BLOCKER

Required merchant communications: delivery ID (`support_reference`), `external_delivery_id`,
pickup/dropoff times, and the order items.

`resources/js/pages/Admin/TenantAdmin/Orders/Show.vue` takes exactly three props —
`restaurant`, `order`, `events` (`:30-34`). No delivery assignment, so the restaurant cannot see the
courier ETA, the tracking link, the DoorDash IDs, or whether a Dasher has even been assigned. The
order timeline (`OrderEvent`) records dispatch attempts, but that is an audit log, not a status
panel.

**Fix:** same DTO as G1, rendered as a delivery panel on the admin order page. This is the screen
you will be sharing during the demo when they say "now show me the merchant view."

## G4 — No restricted-items safeguard · BLOCKER

DoorDash's integration requirements state that integrations **must include safeguards against
restricted items** (tobacco, cannabis, weapons, explosives). Alcohol is a separate regime: it
requires the merchant to be licensed in its jurisdiction, a **signed alcohol addendum** to the Drive
agreement, and on the API side an `items` list, an `order_contains` field, and
`action_if_undeliverable` set to `return_to_pickup`.

Grepping `app/` and `resources/` for `alcohol`, `tobacco`, `cannabis`, `restricted`,
`order_contains`, `action_if_undeliverable` returns **nothing** relevant — the only `restricted`
hits are Stripe account states.

Plateful lets a restaurant put arbitrary text on a menu and the AI importer will happily extract a
beer list. In Utah, a brewpub or a restaurant with a liquor license is a plausible early customer.

**Decide before the call — they will ask.** Three options, not mutually exclusive:

1. **Policy attestation.** Terms clause + an onboarding checkbox: restricted items may not be sold
   through Plateful delivery. Cheapest, and it is the honest answer if we are food-only.
2. **Checkout guard.** A keyword blocklist checked on delivery orders, blocking dispatch. Crude, but
   it is a demonstrable *technical* safeguard, which is what the requirement asks for.
3. **Alcohol as a future feature.** Explicitly out of scope for launch; revisit with the addendum.

Recommendation: ship 1 + 2 before the demo and say so plainly on the call. "We are food-only, here
is the contractual bar and here is the technical bar" is a strong answer. "We hadn't considered it"
is not.

## G5 — No `pickup_time` on the quote · HIGH

DoorDash's required-fields list wants **either `pickup_time` or `dropoff_time`**, UTC, ISO-8601.
`DoorDashProvider::quotePayload()` (`:262-285`) sends neither — it sends ids, addresses, contacts,
and dropoff instructions only. Sandbox quotes returned `HTTP 200` anyway, so DoorDash is silently
defaulting to ASAP.

This is not just a checklist item. `prep_time_minutes` exists on the restaurant (default 5, added
2026-07-14) and **is not used here**, so we are telling DoorDash to send a Dasher immediately for
food that isn't cooked. In production that is a Dasher idling in the dining room and a bad rating on
our merchant.

**Fix:** send `pickup_time = now + prep_time_minutes` as UTC ISO-8601.

## G6 — No `items` / `order_value` on the quote · HIGH

`items` is recommended for all deliveries and **required** for any restricted-item flow;
`order_value` is recommended and is what DoorDash uses for coverage and liability on the parcel.
Neither is in `quotePayload()`. We have both to hand — the order lines and the food subtotal.

**Fix:** map order lines to `items` (name, quantity, external id) and send `order_value` = food
subtotal in cents. Cheap, and it makes the API-log review boring, which is what you want.

## G7 — Webhook signature scheme assumed · MEDIUM

`DoorDashWebhookController::signatureIsValid()` guesses the header name and base64-vs-hex encoding.
Already tracked in the plan's "still open" list. Confirm in the DoorDash portal at webhook setup —
before the demo if the portal exposes it, since webhook ingestion across the lifecycle is on the
validation checklist.

## G8 — Cancel-response fee field assumed · MEDIUM

`DoorDashProvider::parseCancellation()` (`:212-237`) probes several plausible field names and
**defaults to courier-fee-retained when the response is silent**. That default is the right way
round — it never refunds on a guess — but it means a genuinely free cancellation may not be refunded
to the customer. Confirm the real field at portal setup and tighten the one method.

## G9 — Quote-failure copy unrehearsed · LOW

Error handling is on the review list: user-friendly messages when DoorDash rejects a quote. Plateful
handles the *behaviour* correctly — a failed quote means delivery is simply not offered, which
doubles as the out-of-range check. Before the demo, walk an out-of-range address through checkout
and read what the customer literally sees. Make sure it is a sentence, not a status code.

---

## Status-map note (not a gap)

`DoorDashStatusMap` collapses DoorDash's lifecycle into six cases and treats `confirmed` as the
courier-assigned signal that auth/capture waits on. That is correct and defensible. But once G1
ships, the customer will see whatever these map to — `enroute_to_dropoff` and `arrived_at_consumer`
both flatten to `picked_up`, which reads as stale on a tracking card. Consider surfacing the raw
DoorDash status as display text alongside the mapped enum, without changing the money logic that
depends on `hasCourier()`.

---

## Suggested order of work

1. **G4 decision** — it is a policy call, not code, and it gates what you build.
2. **G5 + G6** — two payload fields, an hour, makes the API-log review clean.
3. **G2** — migration + two lines in the provider. Unblocks G3.
4. **G1 + G3** — one `DeliveryAssignmentData` DTO serving both surfaces. The bulk of the work.
5. **G9** — rehearse the copy.
6. **G7 + G8** — confirm at portal setup; they cannot be closed before you have portal access.

G7 and G8 are the only items that *require* production access to resolve. Everything else can be
done and demoed in sandbox now.

---

## Sources

- [Integration requirements | DoorDash Developer Services](https://developer.doordash.com/en-US/docs/drive/overview/integration_requirements/)
- [How to build for restaurants | DoorDash Developer Services](https://developer.doordash.com/en-US/docs/drive/how_to/build_for_restaurants/)
- [Get production access | DoorDash Developer Services](https://developer.doordash.com/en-US/docs/drive/how_to/get_production_access/)
