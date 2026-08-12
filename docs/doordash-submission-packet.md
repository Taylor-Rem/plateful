# DoorDash Drive — production access submission packet

_Prepared 2026-07-31. Everything you need to file, in the order you file it. Pair with
[doordash-certification-readiness.md](doordash-certification-readiness.md) — close the blockers
there before booking the demo._

---

## The split that matters

DoorDash's process has two doors. **Only the second one was waiting on the bank account — and as
of 2026-08-12 that account is open (Mercury), so both doors are unblocked. Nothing is waiting on
us but the filing itself.**

| | Part A — record interest | Part B — production access request |
|---|---|---|
| What | A short Google Form | Portal request → business details → **payment method** → accept Drive terms → Zoom demo |
| Needs a payment method? | **No** | **Yes** — the card/ACH DoorDash centrally bills |
| Needs the EIN or bank? | **No** | Yes, if you want it on the business account — **both now exist** |
| Can you do it today? | **Yes** | **Yes** — unblocked 2026-08-12 (Mercury account open) |
| Cost | Zero | Zero, but starts the real clock |

**File Part A today.** It costs nothing, needs nothing you don't already have, and it starts the
relationship while production access sits "currently restricted with no certification timeline."
That sentence is the whole reason to be early.

Form: https://docs.google.com/forms/d/e/1FAIpQLSfggU_NjGWCdi9vyWUicrnzJmtu9vC4zgbfSC3ROwSvW4eV2g/viewform

---

## Part A — answers to paste

| Field | Answer |
|---|---|
| Company | Plateful LLC |
| Website | https://plateful.fyi |
| Contact name | Taylor Grant Remund |
| Contact email | _your working address_ |
| Contact phone | 435-901-7141 |
| Country / region | United States — Utah |
| What are you building? | A multi-tenant online-ordering platform for independent restaurants. Each restaurant gets its own branded storefront; we need Drive to fulfil delivery orders placed on that storefront. |
| Use case | Restaurant delivery — first-party orders on the restaurant's own channel, dispatched to Drive. We supply the demand; Drive supplies the courier. |
| Integration model | Umbrella / central billing — one platform credential set, per-request DD-JWT-V1, Businesses and Stores provisioned per restaurant. |
| Stage | Integration complete in sandbox; JWT auth, quote, accept, status webhooks, cancellation and refunds all built and tested. Preparing for certification. |
| Expected volume | Launch: 1–5 restaurants in Utah County. 12 months: 100–300 Utah restaurants. |
| Timeline | Ready to demo once our production account is provisioned; launching as soon as access allows. |

---

## Part B — production access request answer sheet

The business bank account now exists (Mercury, open as of 2026-08-12) — this can be filed
immediately. Keep this open in a second window.

### Business details

| Field | Answer |
|---|---|
| Legal entity name | Plateful LLC |
| DBA / trade name | Plateful |
| Entity type | Single-member LLC, disregarded entity |
| State of organization | Utah |
| Utah entity number | 14714085-0160 |
| Filing effective date | July 10, 2026 |
| EIN | ✅ **Assigned 2026-08-06** — EIN on file (see private records; never written into this repo). Official CP 575 letter to follow by mail. |
| Business address | 975 W 540 S, American Fork, UT 84003 |
| County | Utah County, Utah |
| Responsible party | Taylor Grant Remund, Sole Member |
| Phone | 435-901-7141 |
| Website | https://plateful.fyi |
| Business category | Software / technology — online food-ordering platform (SaaS) |

### Payment method — ✅ UNBLOCKED (was the one blocked field)

DoorDash centrally bills **Plateful**, not the restaurant — this is the umbrella model the whole
money design assumes (`DeliveryProviderName::isCentrallyBilled()`, customer gross-up and
central-billing recovery, Session 4b). So the card or ACH you put here is the account that pays
every courier fee for every restaurant, and gets recovered through the customer gross-up.

You chose to wait for the business bank account — the clean call for an account that will carry
real courier spend. That account is now open (**Mercury**, Plateful LLC), so use it here.
Nothing is holding Part B anymore.

### Integration description

> Plateful is a multi-tenant online-ordering platform for independent restaurants. Each restaurant
> operates a branded storefront on its own subdomain where its customers browse the menu, order and
> pay. Orders are paid through Stripe Connect with the restaurant as merchant of record; Plateful
> takes a 4% platform fee.
>
> We integrate Drive under the umbrella model: one set of platform credentials, a DD-JWT-V1 minted
> per request, and a Business + Store provisioned per restaurant that maps to
> `pickup_external_business_id` / `pickup_external_store_id`. Restaurants store no DoorDash
> credentials themselves.
>
> Flow: the customer enters a delivery address at checkout and we call Create Quote. A failed quote
> means delivery is simply not offered, which doubles as our out-of-range check. The quoted fee and
> ETA are committed to the customer *before* payment. On payment we authorize rather than capture,
> then accept the quote. We capture only once DoorDash confirms a Dasher; if no courier
> materializes, the authorization is voided and the customer is never charged. A deadline job polls
> Drive so a missed webhook costs latency, not correctness.

### Go-to-market / launch strategy

They ask for this and they weight it. The honest version:

> Utah beachhead, founder-led and in person. Our target is the independent restaurant with no
> ordering channel of its own that currently takes all its online volume through the delivery
> marketplaces at 15–40%. We are not trying to replace their register — we integrate with Square and
> Clover — and we are not a marketplace, so we bring no new demand. We are the restaurant's own
> direct channel at 4% flat, capped at $249/month.
>
> Drive is how those direct orders actually get delivered. It is complementary to DoorDash's
> marketplace rather than competitive with it: the orders we dispatch are the restaurant's existing
> repeat customers ordering direct, which would otherwise be pickup-only.
>
> Roughly 2,000 Utah restaurants fit the profile. We need 100–300 to have a real business. Launching
> with a pilot restaurant in Utah County, then referral-driven expansion along the Wasatch Front.

That last framing — complementary, not competitive — is worth saying out loud. You are asking a
delivery marketplace to power a product whose pitch is "stop renting your customers from the
delivery marketplaces." Lead with the courier-network framing, not the fee-comparison framing.

---

## Demo run-sheet

30–60 minutes on Zoom, you screenshare an end-to-end test delivery. They review API logs, both UIs,
error handling, cancellation, compliance, and launch strategy.

### Before the call

- [ ] Readiness blockers G1–G4 closed (see the readiness audit — customer tracking, merchant panel,
      `support_reference`, restricted-items stance).
- [ ] `pickup_time`, `items`, `order_value` on the quote payload (G5, G6).
- [ ] **A queue worker is running.** `composer run dev` or `php artisan queue:work`. Without it
      nothing dispatches and the demo dies at step 4 in front of them.
- [ ] Sandbox creds live, Delivery Simulator ready to advance the delivery through its statuses.
- [ ] A seeded test restaurant with a real menu, hours set, and a delivery-enabled configuration.
- [ ] Two browser windows staged: storefront (customer) and tenant admin (merchant).
- [ ] Rehearse once end to end. Then rehearse the failure paths.

### The run

1. **Storefront** — build a cart, enter a delivery address, show the quoted fee and ETA appearing
   *before* payment. Say the words "we commit the fee to the customer before we charge them."
2. **Checkout** — pay. Point out the authorization, not capture.
3. **API logs** — show the Create Quote and Accept Quote calls: `external_delivery_id`,
   `pickup_external_business_id` / `pickup_external_store_id`, dropoff contact, phone, address
   components, `pickup_time`, `items`, `order_value`, UTC timestamps.
4. **Simulator** — advance to Dasher confirmed. Show the capture firing on courier confirmation.
5. **Customer view** — the tracking card: tracking URL, live delivery status, dropoff time, order ID,
   restaurant phone. _(This screen does not exist yet. G1.)_
6. **Merchant view** — the admin delivery panel: `support_reference`, `external_delivery_id`, pickup
   and dropoff times, items. _(Does not exist yet. G2, G3.)_
7. **Webhooks** — advance through picked-up and delivered; show both UIs updating.
8. **Error path** — an out-of-range address; show delivery quietly not being offered with
   human-readable copy.
9. **Cancellation** — cancel pre-assignment; show the refund/void behaviour and where the merchant
   does it.
10. **Compliance** — state the restricted-items position plainly. Food-only, contractual bar in the
    Terms, technical bar at checkout, alcohol explicitly out of scope pending a signed addendum.
11. **Launch strategy** — the Utah pilot paragraph above.

### Questions to ask them (get these answered while you have a human)

- The exact **webhook signature scheme** — header name and base64 vs hex (closes G7).
- The exact **cancellation-response field** for a retained vs waived courier fee (closes G8).
- Whether `pickup_time` is honoured as a prep-time hint or treated as a hard scheduled dispatch.
- What triggers a re-certification later — do UI changes or new endpoints require another review?
- Expected time from approval to production credentials.

---

## Sources

- [Get production access | DoorDash Developer Services](https://developer.doordash.com/en-US/docs/drive/how_to/get_production_access/)
- [Integration requirements | DoorDash Developer Services](https://developer.doordash.com/en-US/docs/drive/overview/integration_requirements/)
- [How to build for restaurants | DoorDash Developer Services](https://developer.doordash.com/en-US/docs/drive/how_to/build_for_restaurants/)
