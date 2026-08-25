# Campaigns plan — opted-in email & SMS remarketing

_Drafted 2026-08-19. Companion to `docs/customers_page_plan.md` (which owns Phases 1–2);
this doc owns consent capture and Phases 3 (email campaigns) and 4 (SMS). Tracked as
todo.md §4 "fee-free remarketing". Status: **consent capture (the Phase 1 amendment
below) BUILT 2026-08-24** — pivot columns, `marketing_consent_events` audit table,
checkout checkbox, account toggle, and the signed unsubscribe endpoint all shipped
with the Customers page. Phases 3–4 remain planned, not started — decisions below
marked ⚑ are Taylor's to confirm before the relevant build. **Phase 3 build spec:
`docs/campaigns_implementation_plan.md` (sessions 1–4, drafted 2026-08-25)** — build
from that doc, not this one; this doc owns strategy and compliance rationale._

## Decisions already made (2026-08-19)

1. **Email first, SMS later.** Phase 3 is email end-to-end; SMS is a separate Phase 4
   with its own consent capture, provider setup, and 10DLC registration. TCPA's
   $500–$1,500-per-text statutory damages are the same risk class that killed cold
   outreach; email under CAN-SPAM is an opt-out regime with no registration regime.
2. **Strict opt-in only, both channels.** Only customers who affirmatively checked the
   marketing box are campaignable — even though CAN-SPAM would legally allow emailing
   past customers. Rationale: shared-domain deliverability (one restaurant's spam
   complaints hurt every restaurant), and it matches the pitch ("these people asked to
   hear from you"). No re-permission seed blast to historical customers (considered,
   rejected with strict opt-in).
3. **Consent capture ships with Phase 1** (Customers page), not with Phase 3. The list
   only accrues from the day the checkbox exists; the lighthouse's early order volume
   is exactly the list campaigns will want. See "Phase 1 amendment" below.
4. **Shared marketing domain** for email sender identity: one platform-owned domain
   dedicated to marketing, from-name = restaurant name, reply-to = the restaurant's own
   `restaurants.email`. Marketing traffic never shares a domain with transactional mail
   (order confirmations must not inherit campaign complaints).

## How the four sellable benefits map to phases

| Benefit | Served by |
|---|---|
| (1) Free repeat-order prompts (slow-Tuesday lever) | Phase 3 compose + send; Phase 4 adds SMS |
| (2) Noticing a lapsed regular (win-back) | Phase 2 stats + Phase 3 "lapsed 60+ days" audience filter (later: automated nudge, see Backlog) |
| (3) Insurance against platform rate hikes (portability) | Phase 1 CSV export, amended to include consent status so the exported list is legally usable in Mailchimp/etc. |
| (4) The list as a business asset | All of it — plus the opted-in count on the Customers page as the number that grows week over week |

---

## Consent data model (built in Phase 1, reused by Phases 3–4)

Consent is **per-restaurant, per-channel** — the customer opted into *that restaurant's*
marketing, and the restaurant is the seller. (The FCC's one-to-one consent rule was
vacated in Jan 2025, but per-restaurant consent was never in question here — it's what
the ownership pitch means.)

**Columns on `restaurant_customer`** (fast to query, joins the Customers page for free):

- `marketing_email_opted_in_at` (timestamp, nullable)
- `marketing_email_opted_out_at` (timestamp, nullable)
- Phase 4 adds: `marketing_sms_opted_in_at`, `marketing_sms_opted_out_at`,
  `marketing_sms_phone` (E.164 snapshot of the number consent was given for)

Eligible = opted_in_at not null AND opted_out_at null AND user not soft-deleted AND not
on the suppression list (Phase 3). Re-opt-in after an opt-out clears `opted_out_at` and
re-stamps `opted_in_at`.

**`marketing_consent_events` audit table** (append-only proof of consent — nice for
email, *mandatory* for TCPA, so build it once): `id, user_id, restaurant_id, channel
(email|sms), action (opted_in|opted_out), source (checkout|account|unsubscribe_link|
admin|sms_stop), ip, user_agent, consent_text_snapshot, created_at`. The
`consent_text_snapshot` stores the exact checkbox label shown — TCPA disputes turn on
what the consumer actually agreed to.

**Where capture happens:**

- **Checkout** (`Checkout.vue` + `CheckoutRequest` + `OrderPlacement::materialize`):
  unchecked-by-default checkbox, "Email me offers and news from {Restaurant}". Persist
  at order materialization (payment success), same transaction that upserts the pivot.
  Logged-in customers only in v1 — guests have no user/pivot row.
  ⚑ **Guest consent** is deliberately deferred: capturing it means an email-keyed
  consent store separate from the pivot, and the Customers page doesn't show guests
  anyway. Revisit if guest-order share turns out high at the lighthouse.
- **Storefront account page** (`/account`): a per-restaurant toggle so customers can
  subscribe/unsubscribe themselves. (Registration checkbox is optional — checkout is
  where the relationship is; ⚑ skip on Register.vue unless you want it.)
- **Unsubscribe endpoint**: signed URL (`URL::signedRoute`), no login required, works
  from any email client: one click → opted out, confirmation page with undo. Route
  lives on the storefront domain of the restaurant. Built in Phase 1 even though
  nothing sends yet — the account toggle and the CSV "consent" column reference it,
  and it must exist before the first campaign ever goes out.

**Phase 1 amendment to `docs/customers_page_plan.md` scope:**

- Migration + pivot columns + consent-events table as above.
- Checkout checkbox + account toggle + unsubscribe route.
- Customers page: "Marketing ✓" badge per row, opted-in count in the header, and an
  "opted-in only" filter.
- CSV export gains `marketing_opt_in` + `opted_in_at` columns — this is the
  portability story: the exported file is a *legally usable* list, not just data.
- Tests: consent persists on checkout, unsubscribe link works logged-out, consent
  events written, export includes consent, cross-restaurant scoping (opting into
  restaurant A never opts into B).

**Effort: ~1 session** on top of the existing Phase 1 estimate.

---

## Phase 3 — email campaigns

### Compliance requirements (CAN-SPAM + the 2024 Gmail/Yahoo bulk-sender rules)

CAN-SPAM (15 U.S.C. §7704) per message: truthful header/from, non-deceptive subject,
clear identification as an advertisement, a **valid physical postal address** of the
sender, a conspicuous unsubscribe mechanism, opt-outs honored within **10 business
days**, no sending to opted-out addresses. The *restaurant* is the sender: footer shows
the restaurant's name + street address (already on `restaurants` — required columns) +
"sent via Plateful" + unsubscribe link. Penalties are per-email (up to ~$53k per
violation, FTC-enforced) and both the restaurant *and* the platform can be liable —
which is why the footer, unsubscribe, and suppression are platform-enforced, never
restaurant-editable.

Gmail/Yahoo bulk-sender requirements (enforced since 2024, table stakes now): SPF +
DKIM + DMARC on the sending domain, **one-click List-Unsubscribe** (`List-Unsubscribe`
+ `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, RFC 8058) on every message, and
spam-complaint rate kept under 0.3% (target < 0.1%). These are hard-coded into the
send path, not optional.

### Sending identity & infrastructure

- **Marketing domain — DECIDED + PURCHASED 2026-08-25: `platefuloffers.fyi`**
  (registered at Porkbun). A dedicated domain rather than a subdomain of the app
  root: Gmail weighs reputation partly at the organizational-domain level, so a
  separate domain fully insulates `plateful.fyi` transactional + app mail from
  campaign complaints. From: `{Restaurant Name} <{subdomain}@platefuloffers.fyi>`,
  reply-to `restaurants.email`. Verify once in Resend (SPF/DKIM/DMARC), set up
  Google Postmaster Tools on it. Do the DNS early — reputation ages slowly.
  **Setup done 2026-08-25**: domain added to Resend (region us-east-1, sending
  only — receiving off, reply-to is the restaurant's inbox) and all DNS records
  (DKIM TXT, send/rsend CNAMEs, `_dmarc` TXT `p=none`) live at Porkbun and
  confirmed propagated; Resend verification was pending at time of writing and
  completes automatically. Still todo from this list: Google Postmaster Tools,
  and tightening DMARC from `p=none` once sending is established.
- **Per-restaurant custom sending domain (DECIDED 2026-08-25, build after shared
  sending works)**: the shared domain is the default, but a restaurant may configure
  its own sending domain. Captured in the onboarding wizard in the same step that
  asks about their site domain ("what email do you want offers to come from?").
  Reality check baked into the UX: you can only send as a domain you can prove —
  the restaurant's domain gets added to Resend as its own verified domain and the
  wizard shows the SPF/DKIM records to add (mirroring the existing custom-site-domain
  request flow); sending falls back to the shared domain until verification passes.
  Needs: `sending_domain` + `sending_from_local_part` + verification status on
  `restaurants` (or a small table), a Resend domain per opted-in restaurant, and the
  send path picking the verified identity per restaurant. Their domain, their
  reputation — which is also the sales upside ("your emails come from you").
- **Resend, plain send API — not Broadcasts.** Broadcasts/Audiences prices per contact
  ($40/mo at 5k contacts) and its segmentation can't express "lapsed 60+ days" — our
  audiences are queries over the pivot, so consent, segmentation, and unsubscribe all
  have to live in our DB regardless. The send API prices per email, supports the batch
  endpoint (100 emails/request, per-recipient headers for the signed unsubscribe URL),
  and is what we already run transactional mail on. New config: a `marketing` entry in
  the `MailSender` map + the new domain in Resend.
- **Suppression via Resend webhooks** (`email.bounced`, `email.complained`): hard
  bounce → global per-email suppression (email is dead everywhere); complaint →
  automatic opt-out for that restaurant + counts toward the restaurant's complaint
  rate. New webhook controller (pattern exists: Stripe webhook controller).

### Data model

- `campaigns`: `restaurant_id`, `subject`, `preheader`, `body` (see editor below),
  `audience_filter` (json: all | lapsed_days>=N | min_orders>=N), `status`
  (draft → scheduled → sending → sent | cancelled | paused_by_platform),
  `scheduled_at`, `sent_at`, counters (`recipients_count`, `delivered_count`,
  `bounced_count`, `complained_count`, `unsubscribed_count`), timestamps.
- `campaign_recipients`: `campaign_id`, `user_id`, `email` (snapshot), `status`
  (queued | sent | failed | bounced | complained | unsubscribed), `resend_message_id`,
  `sent_at`. Snapshot matters: the audience is frozen at send time and the row is the
  audit record of exactly who was mailed.
- `suppressed_emails`: `email` (unique), `reason` (hard_bounce | complaint | manual),
  `created_at`. Checked at send time for every recipient, platform-wide.

### Send pipeline

Queued fan-out on the existing database queue: a `SendCampaign` job resolves the
audience query (re-checking consent + suppression at execution time, not enqueue
time), chunks into `SendCampaignBatch` jobs of ≤100 via `Bus::batch` (the
`job_batches` table already exists, first real use), each batch calls Resend's batch
endpoint with per-recipient signed unsubscribe URLs. Throttle to respect Resend's
rate limit (2 rps default — request a raise when volume warrants). Jobs hold ids, not
models, per the existing queue convention (no bound tenant in workers). Batch
completion callback flips campaign status to `sent` and finalizes counters.

### Owner-facing UI (`/{subdomain}/campaigns`, admin-role only)

- Index: campaign list with status + headline stats (sent / delivered / unsubscribed /
  complaints). Empty state sells the feature ("You have N opted-in customers…").
- Compose (v1 deliberately minimal — this is a slow-Tuesday tool, not Mailchimp):
  subject, preheader, a simple body (⚑ recommend structured template: headline,
  body text, optional item/offer callout, CTA button linking to the storefront —
  rendered into a platform-controlled Blade layout that carries the restaurant's
  logo/colors like `order-confirmation.blade.php` does, with the compliance footer
  hard-coded. No free-form HTML: it's a deliverability and abuse surface).
- Audience picker: all opted-in / regulars (N+ orders) / lapsed (no order in N days) —
  same filters as the Customers page, with a live recipient count.
- Preview + **send test to self** + schedule-or-send-now + cancel-while-scheduled.
- Per-campaign report page: delivered/bounced/complained/unsubscribed.
  ⚑ Open-rate tracking: skip in v1 (pixel tracking is noisy post-Apple-MPP and
  adds nothing to the pitch); click-through on the CTA is cheap later if wanted.

### Abuse prevention (one bad restaurant poisons the shared domain)

- Only `approved` + active restaurants with completed Stripe onboarding can send.
- **Platform caps** (config, per restaurant): max campaigns/week (default 2) and a
  send-volume ceiling; enforced in the controller *and* the job.
- ⚑ **First-campaign review**: recommend the first campaign from each restaurant
  lands in a super-admin approval queue (a status + one admin-host page + email ping);
  after one clean send the restaurant is auto-approved. Cheap while restaurant count
  is single-digit; drop or automate later.
- **Automatic pause**: complaint rate > 0.3% on a campaign, or 2+ complaints while
  small-N, → campaign halts mid-send and the restaurant's sending is
  `paused_by_platform` pending review.
- Compliance footer, unsubscribe headers, and suppression checks live in the platform
  send path — no restaurant-editable surface can remove them.
- ToS/merchant-agreement clause: acceptable content, consent warranty, platform right
  to suspend sending. (Legal-doc task, not code.)

### Testing

Scoping (restaurant A cannot see/send B's campaigns), consent enforcement (opted-out
and suppressed never enqueued — including opt-out *between* schedule and send),
audience filters, caps, webhook → suppression/opt-out, unsubscribe signed-URL, footer
contents, batch idempotency (a retried batch job must not double-send: guard on
`campaign_recipients.status`).

### Effort: ~5 sessions

(1) migrations + models + send pipeline; (2) webhooks + suppression + caps/pause;
(3) compose/index UI + audience picker; (4) template rendering + preview/test-send +
report; (5) review queue + hardening + full test pass. Plus one-time ops: buy domain,
DNS, Resend + Postmaster setup (an hour, but DNS propagation says start early).

---

## Phase 4 — SMS campaigns (only after email has proven the motion)

**Why hard-gated:** TCPA private right of action = $500/text, $1,500 willful,
uncapped class exposure — one bad blast to 200 people is a six-figure mistake. This
phase does not start until (a) a restaurant is actually asking for it, and (b) an
SMS-consented list exists to send to.

### Compliance requirements (verified current as of 2026-08)

- **Prior express written consent** per restaurant: a separate, unchecked checkbox
  with the disclosure formula — "I agree to receive recurring marketing texts from
  {Restaurant} at the number provided. Consent is not a condition of purchase.
  Msg & data rates may apply. Msg frequency varies. Reply STOP to cancel, HELP for
  help." E-SIGN-compliant record = our `marketing_consent_events` row with the exact
  text snapshot, number, timestamp, IP. (The FCC one-to-one consent rule was vacated
  Jan 2025 and formally eliminated late 2025 — irrelevant to us anyway; consent here
  is per-restaurant by design.)
- **Revocation rule (in force since Apr 2025)**: opt-out by *any reasonable method*
  (STOP, email, phone call, a sentence in a reply) honored within **10 business
  days** — so inbound SMS replies must be parsed/reviewed, not black-holed. The
  broader "revocation-all" rule is delayed to Jan 31 2027 — re-check before building.
- **Quiet hours**: 8am–9pm recipient local time (federal); use the restaurant's
  timezone as proxy (customers are local by nature). ⚑ Some state mini-TCPAs are
  stricter (FL FTSA 8am–8pm, OK, WA) — Utah-only customers make this low-risk today,
  but the send window should be config, not code.
- **10DLC registration** (carrier-mandated for application SMS): Plateful registers
  as an ISV brand with The Campaign Registry (standard vetting ~$40–50 one-time),
  then ⚑ **one shared marketing campaign** covering all restaurants (one use case:
  "restaurant promotional offers to opted-in customers") vs per-restaurant
  sub-campaigns ($15 vetting + ~$2–10/mo *each*). Recommend shared campaign while
  small — carriers accept ISV shared campaigns for a uniform use case; revisit if a
  carrier or volume forces sub-campaigns. **Lead time: 2–6 weeks** — start
  registration the day this phase is greenlit.

### Build

- **Provider**: Twilio (best ISV/10DLC tooling + docs; Telnyx is the cheaper
  alternative — ⚑ confirm at build time). New service class + config; one 10DLC
  number initially (all restaurants share it; from-identity is the message prefix
  "{Restaurant}: …" — customers key on content, not number).
- **Phone data**: today `users.phone` is sparse (checkout writes phone to the order,
  never the user). The SMS opt-in checkbox therefore captures/confirms the number at
  checkout and snapshots it to `marketing_sms_phone` on the pivot; consent is to a
  *number*, not a person.
- **Opt-in capture**: second checkbox at checkout (never bundled with the email
  checkbox — TCPA consent must be separately and clearly given), account toggle,
  consent-event rows with text snapshot.
- **STOP/HELP**: Twilio Advanced Opt-Out for the keywords + a webhook that also
  parses non-keyword replies into a review queue (the "any reasonable method" rule);
  STOP at the shared-number level opts the number out of **all** restaurants on that
  number — carrier-level reality, document it honestly in the admin UI.
- **Pipeline**: reuse the campaign model (`channel` column on `campaigns`), same
  audience/caps/pause machinery, per-segment cost estimate shown *before* send
  ("312 recipients ≈ $3.10").
- ⚑ **Cost model**: SMS has real marginal cost (~$0.008–0.01/segment + carrier fees
  + monthly campaign fee). Options: pass through at cost, cost-plus, or bundle N
  texts/month into the platform fee. Decide before build; leaning pass-through at
  cost (keeps "fee-free remarketing" honest — the fee-free part is email).

### Effort: ~4–5 sessions of code + 2–6 weeks external lead time (10DLC), plus the
consent-list accrual time — which is why the SMS checkbox *could* ship earlier than
the SMS pipeline. ⚑ Decide at Phase 3 time whether to add the SMS checkbox then
(costs a day, starts the clock on the SMS list) or keep checkout to one checkbox
until Phase 4 is real.

---

## Sequencing (relative to the lighthouse timeline)

1. **Lighthouse live + first weeks** → Phase 1 (Customers page + CSV) **including the
   consent-capture amendment**. Every order from week one grows the campaign list.
2. **Before the ~60-day money story** → Phase 2 (regulars stats), per the existing plan.
3. **~60–90 days in**, once the opted-in list is worth mailing (say 50+ consents) and
   before DoorDash-Storefront-segment outreach → **Phase 3 email campaigns**. The
   demo becomes: Customers page → Export → *and* "email your regulars from right
   here, free." Buy the marketing domain + set up DNS well ahead (reputation ages
   like DNS does — slowly).
4. **Phase 4 SMS**: pulled by demand, not pushed by roadmap. Trigger = a paying
   restaurant asking + email campaigns running cleanly. Start 10DLC paperwork the
   same day.

## Backlog (explicitly not in any phase yet)

- Automated win-back nudge ("regular X hasn't ordered in 45 days — send them
  something?") — needs Phase 2 stats + Phase 3 sending; the obvious v2.
- Guest-checkout consent capture (email-keyed consent store).
- Click-through tracking on campaign CTAs.
- Loyalty tie-in ("double points this Tuesday") — blocked on §10 redemption.

## Decision points recap (⚑ = Taylor)

| # | Decision | Leaning | Needed by |
|---|---|---|---|
| 1 | Marketing domain name (separate purchased domain) | **DECIDED: `platefuloffers.fyi`, purchased 2026-08-25** | done — DNS/Resend setup next |
| 1b | Per-restaurant custom sending domain (opt-in, wizard-captured, shared domain default) | **DECIDED 2026-08-25: yes** | after shared-domain sending works |
| 2 | Guest consent capture | defer | revisit post-lighthouse |
| 3 | Opt-in checkbox on Register.vue too | skip, checkout only | Phase 1 build |
| 4 | Structured template vs free-form email body | **DECIDED 2026-08-25: structured template** | done |
| 5 | First-campaign super-admin review queue | **DECIDED 2026-08-25: yes** | done |
| 6 | Open tracking | skip v1 | Phase 3 build |
| 7 | SMS shared 10DLC campaign vs per-restaurant | shared | Phase 4 registration |
| 8 | SMS provider (Twilio vs Telnyx) | Twilio | Phase 4 build |
| 9 | SMS cost pass-through model | pass-through at cost | Phase 4 build |
| 10 | Ship SMS checkbox early to accrue consent | decide at Phase 3 | Phase 3 build |
