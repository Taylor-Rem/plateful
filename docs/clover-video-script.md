# Clover App Market — functional review video

Clover's app approval needs one functional video (30 s – 3 min) showing the merchant
workflow end to end, with narration that justifies every permission. Plateful is a REST
web app, not a payments app, so no Remote App ID and no per-transaction payment videos.
This script was written against the flow verified live in the Clover sandbox on
2026-09-09 (order → paid ticket on the test merchant's register).

Related: [docs/pos-integration-strategy.md](pos-integration-strategy.md) §3 (Clover phase),
`todo.md` §0 (Clover production env vars).

## Pre-flight (do all of this before recording)

### Every Thursday: the dev database resets from production

The reset wipes three things the demo depends on. After a reset:

1. **Re-point testaurant at the test-mode Stripe account** (the copy carries the live
   account, which the local test key can't charge — checkout errors with a Stripe
   `PermissionException`):

   ```bash
   php artisan tinker --execute '$r = App\Models\Restaurant::where("subdomain","testaurant")->first(); $r->forceFill(["stripe_account_id" => "acct_XXXXXXXXXXXX"])->save(); echo $r->stripe_account_id."\n";'
   ```

2. **Reconnect Clover** on testaurant: admin → testaurant → Settings → POS → Connect.
   The app is still installed on the sandbox test merchant, so this is one hop through
   Clover's authorize screen. (`pos_integrations` is wiped by the reset.)
3. **Re-register the demo customer** on `https://testaurant.plateful.test/register` if
   you want a signed-in customer on camera. Guest checkout needs nothing.

### Always

- `composer run dev` — includes the queue worker. Without it the push job just queues
  and nothing reaches Clover.
- Log in at **sandbox.dev.clover.com** (separate account from clover.com; sessions
  expire in minutes). Test merchant: **HV1RYZE0YREE1**. Orders page:
  `https://sandbox.dev.clover.com/orders/m/HV1RYZE0YREE1/orders`
- Sandbox app **RCE4JHDRP2E3R** settings should already be: permissions Orders R/W,
  Merchant R, Payments W; Site URL `https://admin.plateful.test/pos/clover`; Alternate
  Launch Path `/pos/clover/launch`; OAuth response Code.
- **Do not use "Sign in with Google" locally** — its callback is registered on
  plateful.fyi and drops you onto production (live Stripe). Guest checkout or email
  sign-in only. Confirm the address bar says `plateful.test` and Stripe's page shows the
  **Sandbox** badge before paying.
- Stripe test card: `4242 4242 4242 4242`, any future expiry, any CVC, any ZIP.
- Three tabs ready: storefront (`https://testaurant.plateful.test`), admin POS page
  (`https://admin.plateful.test/testaurant/settings/pos`), sandbox Orders page.
- Optional: delete old test tickets from the sandbox Orders page so one clean ticket
  appears on camera. Sandbox data only.

## Recording

1080p screen recording, plain voiceover, no music. Target ≈ 3 minutes.

### Scene 1 — Title (10 s)

Storefront home page on screen.

> "Plateful is commission-free online ordering for independent restaurants. This is
> testaurant's own ordering site. The Clover integration sends every paid online order
> straight to the restaurant's Clover register, so there's no tablet to watch and the
> restaurant keeps its customer."

### Scene 2 — Connecting Clover (45 s)

Admin → Settings → POS. Clover should read **Not connected** (click Disconnect first if
it's connected, so the whole flow is on camera).

> "The restaurant owner connects Clover once from their Plateful settings."

Click **Connect**. On Clover's authorize screen, pause and read the permissions:

> "Orders read and write, so a paid order becomes a ticket on the register. Payments
> write, so the ticket is marked paid with the amount the customer already paid online.
> Merchant read, so we can find the register's external-payment tender. That's everything
> we ask for."

Approve. Land back on the POS page showing **Connected**.

### Scene 3 — The order (60 s)

Storefront tab. Open **Classic Italian (Torpedo)**, pick **12"**, type a special
instruction (e.g. "Extra pepperoncini please"), quantity **2**, Add to cart. Open the cart,
**Checkout**. Name, email, a tip, **Place order**.

On Stripe's page:

> "Payment is taken by Stripe on the restaurant's own site. Clover is never asked to
> process the card."

Enter the test card, **Pay**, land on the confirmation page.

### Scene 4 — The register (45 s)

Sandbox Orders tab, refresh. Find the new ticket.

> "Within seconds the ticket is on the restaurant's Clover. The note carries the Plateful
> order number, the customer's name, and pickup or delivery. Each sandwich is its own line
> with the size and the customer's instructions, priced at what they paid. And the status
> is Paid, with the online payment recorded as an External Payment, so staff never charge
> twice."

Click **Details** on the ticket so the line notes are visible.

### Scene 5 — Admin and lifecycle (20 s)

Admin order page for the new order. Point at the timeline entry
"POS push succeeded (clover), ticket …".

> "Plateful records the Clover ticket id on the order. If a push ever fails, the order is
> flagged here — never silently dropped."

Back on the POS page:

> "The owner can disconnect at any time, and uninstalling the app on Clover revokes our
> access."

### Scene 6 — Close (10 s)

Support page with email and phone, then the privacy and terms links.

> "Plateful is free to install from the App Market. Support is by email and phone."

## What reviewers check that this covers

- Install/launch from the App Market or Merchant Dashboard lands somewhere sensible
  (Site URL → `/pos/clover/launch` → sign in → connect flow, or restaurant picker).
- Every requested permission is used and explained on camera.
- Orders created via REST appear in the Clover Orders app with descriptive line items,
  correct totals, and a **paid** state.
- Payments are not taken through Clover by a web app (Stripe handles the card).
- Support contact, privacy policy, and terms exist and are shown.

## Known presentation quirk

The recorded payment is subtotal + tax while the ad-hoc lines carry no Clover tax rate,
so the dashboard shows e.g. Payments $11.00 against a $10.00 ticket total. Restaurants
get Clover tax reporting this way. If the mismatch bothers you on camera, the alternative
is to pay the subtotal only (`CloverPosProvider::recordExternalPayment`).
