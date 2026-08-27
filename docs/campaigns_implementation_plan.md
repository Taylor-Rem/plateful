# Email Campaigns — Implementation Plan (Phase 3)

**Status: LIVE IN PRODUCTION 2026-08-26** (Sessions 1–3 + automated review; Session 4 not
started) · **Decisions locked 2026-08-25** (review-queue decision amended 2026-08-26 — see
Session 3) · Strategy + compliance rationale live in `docs/campaigns_plan.md` (this doc is the
build spec; that doc is the why). Consent capture (Phase 1) is **already built** — see "What
already exists" below before writing anything.

Each session is independently implementable by a fresh session with no prior context beyond this
doc. **Execution order: 1 → 2 → 3 → 4.** Session 2 ends at the demo milestone: *log in as a
restaurant admin, compose a promotional email, send it to multiple test customers.* Session 3 is
required before any real (non-test) restaurant can send. Session 4 is deliberately last.

### Progress

| Session | What | State |
|---|---|---|
| **1** | Send pipeline (data model, audience query, template, Resend batch sender, jobs) | ✅ 2026-08-25 |
| **2** | Owner UI (compose, audience picker, preview, test-send, send/schedule) — **demo milestone** | ✅ 2026-08-25 |
| **3** | Safety rails (Resend webhooks → suppression, auto-pause, caps, first-campaign review queue) | ✅ 2026-08-25; webhook registered in Resend + secret in prod 2026-08-26 |
| **3b** | Automated campaign review via Claude (amends the human-only queue — see Session 3) | ✅ 2026-08-26 (deployed; not yet exercised by a live campaign) |
| **4** | Per-restaurant custom sending domain (wizard-captured, shared domain default) | ⬜ |

### Production state (as of 2026-08-26)

- Sessions 1–3 + automated review are **deployed and proven live**: first real campaign from the
  testaurant went end-to-end — held for first-campaign review → approved in the super console
  (this campaign predated the automated reviewer, so the human path is what got exercised) →
  sent via Resend → delivered to a real Gmail inbox.
- Verified from the delivered .eml: SPF pass (aligned via `rsend.platefuloffers.fyi` custom
  return-path), DKIM pass (`d=platefuloffers.fyi`), DMARC pass, one-click List-Unsubscribe
  headers, plain-text alternative, compliance footer. **The send pipeline needs no fixes.**
- **Deliverability:** that first send landed in Gmail's spam folder — expected cold start for a
  days-old domain (worsened by deliberately spammy test copy). Not a pipeline defect. Remedy is
  warm-up: small consistent sends to genuinely opted-in customers, recipients marking Not spam.
  Weekly cap + recipient ceiling already prevent the burst-from-cold-domain failure mode.
- The automated Claude reviewer is deployed with `CLAUDE_API_KEY` live in prod but has not yet
  ruled on a real campaign. To exercise it: send from a non-graduated restaurant, or set
  `CAMPAIGNS_REVIEW_SPOT_CHECK_RATE=1.0` temporarily; verdict lands in
  `campaigns.review_verdict/review_notes/reviewed_at` and flagged campaigns show the reasoning
  in `/super/campaigns`.
- **Remaining ops:** ~~add `platefuloffers.fyi` to Google Postmaster Tools~~ — **DONE
  2026-08-27**: registered and DNS-verified under the new taylor@tryplateful.fyi Workspace
  account (the 08-26 failures were the wrong-account issue; a root TXT verification record was
  added at Porkbun). Still open: tighten DMARC from `p=none` after a few weeks of established
  sending.

---

## What already exists (built 2026-08-24/25 — do not rebuild)

**Consent data model (Phase 1, shipped):**
- `restaurant_customer` pivot has `marketing_email_opted_in_at` / `marketing_email_opted_out_at`
  plus counters (`total_orders`, `total_spent_cents`, `first/last_ordered_at`). Eligible =
  opted_in set AND opted_out null AND user not soft-deleted. Model
  `App\Models\RestaurantCustomer` has `isEmailOptedIn()` and an `emailOptedIn()` scope.
- `marketing_consent_events` append-only audit table + `App\Models\MarketingConsentEvent`
  (UPDATED_AT null; enums `MarketingChannel`, `MarketingConsentAction`, `MarketingConsentSource`).
- `App\Services\MarketingConsentService`: `optInEmail()` / `optOutEmail()` (idempotent, write
  audit events), `optInText(Restaurant)` (the exact consent label), and
  **`unsubscribeUrl(User, Restaurant): string`** — a relative-signed URL on the restaurant's
  storefront host. **Use this for every campaign email's unsubscribe link and List-Unsubscribe
  header** — the endpoint (`storefront.marketing.unsubscribe`, GET, `signed:relative`, login-free,
  one-click opt-out with undo) already exists in `routes/storefront.php`.
- Customers page (`Admin/TenantAdmin/CustomersController`) with opted-in filter/count — reuse its
  query shapes for audience filters.

**Sending infrastructure (ops, done 2026-08-25):**
- Marketing domain **`platefuloffers.fyi`** is registered (Porkbun) and added to the Resend
  account (region us-east-1, sending enabled, receiving off). DKIM/SPF-CNAMEs/DMARC records are
  live; verification completes automatically. Transactional mail already runs on Resend
  (`plateful.fyi`, verified).
- `resend/resend-php` ^1.3 is already in composer.json — the batch endpoint needs no new
  dependency. **Do not add packages without approval.**
- `RESEND_API_KEY` exists only in the **production** environment (Laravel Cloud). Local dev uses
  SMTP/Mailpit. Therefore the campaign send client MUST be a container-bound service that (a) is
  fakeable in tests, and (b) has a `log`-style local-dev fallback when no key is configured.
- ⚠ Local dev shares its `jobs` table with a tailnet queue worker that can eat mail jobs — for
  local demos run a local worker or the sync driver (see memory/repo notes; not a prod concern).

**Locked decisions (do not re-litigate; rationale in campaigns_plan.md):**
- Strict opt-in only; audiences come exclusively from the consent-eligible pivot rows.
- Shared sending domain default. From: `{Restaurant Name} <{subdomain}@platefuloffers.fyi>`,
  reply-to `restaurants.email`. Session 4 adds opt-in per-restaurant domains.
- **Structured template**, not free-form HTML (locked 2026-08-25): headline, body text, optional
  offer/item callout, CTA button → storefront. Compliance footer is platform-rendered and never
  restaurant-editable.
- **First-campaign review queue: yes** (locked 2026-08-25) — Session 3.
- No open-rate tracking in v1. Email campaigns are free (no per-send billing).

**Repo conventions the executing session must follow** (verify against neighbors before writing):
- Tenant-admin controllers: `app/Http/Controllers/Admin/TenantAdmin/`, `Restaurant $restaurant`
  route-model-bound via `{restaurant:subdomain}` prefix on `admin.<domain>` host; explicit
  `where('restaurant_id', ...)` scoping; every Inertia page passes
  `'restaurant' => RestaurantData::fromModel($restaurant)`. Admin-only routes go inside the
  `admin.restaurant.admin` middleware group in `routes/admin.php` (Customers/Payouts are there).
- DTOs: Spatie Data in `app/Data/` with `#[TypeScript]` + `fromModel()`; run
  `php artisan typescript:transform` after adding.
- Frontend: pages under `resources/js/pages/Admin/TenantAdmin/<Feature>/`, layout via
  `defineOptions({ layout: TenantAdminLayout })`, Wayfinder route imports from
  `@/routes/admin/restaurant/...` (regenerate with `php artisan wayfinder:generate`; note a
  controller method named `export` becomes `exportMethod` — avoid reserved-word method names).
  Sidebar: `resources/js/components/admin/TenantAdminSidebar.vue` (Customers entry is the model).
- Jobs hold **ids, not models** (workers have no bound tenant); use
  `Model::withoutTenantScope()` (static) inside jobs. `job_batches` table already exists —
  `Bus::batch` is available (first real use).
- Tests: Pest, feature tests in `tests/Feature/Admin|Storefront/`, absolute-URL host trick
  (`http://admin.plateful.test/...`), `beforeEach(fn () => config(['platform.primary_domain' => 'plateful.test']))`,
  plain `Model::create` helpers in `*TestHelpers.php` files (`CustomerTestHelpers.php` has
  `customerUser()`/`customerPivot()`). Run `vendor/bin/pint --dirty --format agent` when done.
- Webhooks: `routes/webhooks.php` + CSRF exemption in `bootstrap/app.php`
  (`validateCsrfTokens(except: [...])`); `StripeWebhookController` is the pattern.

---

## Session 1 — Send pipeline (no UI)

Everything needed to take a Campaign row and deliver it, compliantly, to the right inboxes.

**Migrations** (schemas per campaigns_plan.md "Data model"):
- `campaigns`: `restaurant_id` FK, `subject`, `preheader` (nullable), structured body fields
  (`headline`, `body` text, `offer_callout` nullable, `cta_label` nullable, `cta_url` nullable —
  default CTA links to the storefront), `audience_filter` json (`{type: all|lapsed|regulars,
  days?, min_orders?}`), `status`, `scheduled_at` nullable, `sent_at` nullable, counters
  (`recipients_count`, `delivered_count`, `bounced_count`, `complained_count`,
  `unsubscribed_count`, all default 0), timestamps. Index `(restaurant_id, status)`.
- `campaign_recipients`: `campaign_id` FK, `user_id` FK, `email` snapshot, `status`,
  `resend_message_id` nullable, `sent_at` nullable, timestamps. Unique `(campaign_id, user_id)`;
  index `(campaign_id, status)`. The row is the audit record of exactly who was mailed.
- `suppressed_emails`: `email` unique, `reason`, `created_at`.

**Enums** (string-backed, `app/Enums/`): `CampaignStatus` (draft, pending_review, scheduled,
sending, sent, cancelled, paused_by_platform), `CampaignRecipientStatus` (queued, sent, failed,
bounced, complained, unsubscribed), `EmailSuppressionReason` (hard_bounce, complaint, manual).

**Models:** `Campaign` (BelongsToTenant like Order; casts for enums/json/datetimes),
`CampaignRecipient`, `SuppressedEmail`.

**Audience resolver** — `App\Services\CampaignAudience` (or similar): given Restaurant + filter,
return the eligible user rows. Eligibility = pivot `emailOptedIn()` scope AND user not
soft-deleted AND email not in `suppressed_emails`. Filters: `all`; `lapsed` (last_ordered_at older
than N days); `regulars` (total_orders >= N). Also expose `count()` for the UI's live recipient
count. Base it on the Customers-page query in `CustomersController::customersQuery()`.

**Template** — `resources/views/emails/campaign.blade.php`, modeled on
`order-confirmation.blade.php` (restaurant logo/colors). Renders the structured fields plus the
**hard-coded compliance footer**: restaurant name + street address (required columns on
`restaurants`), "sent via Plateful", and the per-recipient unsubscribe link. Nothing
restaurant-editable in the footer.

**Sender** — `App\Services\CampaignMailer` wrapping `Resend::client()` batch endpoint
(≤100/request). Per message: from `{Restaurant Name} <{subdomain}@{marketing domain}>`, reply-to
`restaurants.email`, headers `List-Unsubscribe: <{signed url}>` +
`List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058), html = rendered template.
Config: add `MARKETING_MAIL_DOMAIN=platefuloffers.fyi` env + config entry (follow
`config/platform.php` / `config/mail.php` conventions — check which fits existing style); reuse
`services.resend.key`. Bind an interface or make the class fakeable; no key → log driver
behavior for local dev.

**Jobs:**
- `SendCampaign` (queued, holds campaign id): guard status is `scheduled`/`sending` at execution
  (a cancelled campaign's delayed job must abort silently — this is how cancel-while-scheduled
  works without a scheduler). Resolve the audience **at execution time** (not enqueue time),
  insert `campaign_recipients` snapshots, set status `sending` + `recipients_count`, chunk into
  `SendCampaignBatch` jobs of ≤100 via `Bus::batch` with a completion callback that flips status
  to `sent`, stamps `sent_at`, finalizes counters.
- `SendCampaignBatch` (holds campaign id + recipient ids): re-check each recipient row's status
  is `queued` before sending (idempotent retry — a retried batch must never double-send), re-check
  suppression, call the batch endpoint, mark rows `sent` + store `resend_message_id`. Throttle to
  respect Resend's rate limit (2 rps default — `RateLimited` middleware or sleep between calls;
  request a limit raise when volume warrants).

**Caps config** — `config/platform.php` (or existing config home): campaigns max per week per
restaurant (default 2), recipient ceiling per send. Enforced here in `SendCampaign` (and again in
the controller in Session 2).

**Scheduling note:** no scheduler/cron exists in this app yet. Scheduled sends = delayed dispatch
(`SendCampaign::dispatch($id)->delay($scheduledAt)`) + the status guard above. If this proves
flaky, upgrading to a real scheduler command is a contained change.

**Tests:** audience eligibility matrix (opted-out / soft-deleted / suppressed / cross-restaurant
never included), opt-out between schedule and execution excluded, batch idempotency (retried batch
job sends nothing already `sent`), footer contents + List-Unsubscribe headers present, unsubscribe
URL is the signed Phase-1 URL, caps enforced, cancelled campaign's delayed job aborts.

**Effort:** ~1 session.

---

## Session 2 — Owner UI (**ends at the demo milestone**)

**Routes** (`routes/admin.php`, inside the `admin.restaurant.admin` group, named
`admin.restaurant.campaigns.*`): index, create, store, show, plus POST actions `test`
(send test to self), `send` (send now), `schedule`, `cancel`, and a GET `recipient-count`
(live count for the audience picker; plain JSON via `useHttp` is fine).

**Controller** `TenantAdmin\CampaignsController` + `App\Data\CampaignData`. Only `approved` +
active + Stripe-ready restaurants may send (gate in controller now; re-checked in job in
Session 3). Form Request for store/update validation.

**Pages** `resources/js/pages/Admin/TenantAdmin/Campaigns/`:
- `Index.vue` — campaign list with status badge + headline stats (sent / delivered / unsubscribed
  / complaints). **Empty state sells the feature**: "You have N opted-in customers…" (N from the
  same opted-in count as the Customers page) with a create CTA.
- `Create.vue` — compose: subject, preheader, headline, body, optional offer callout, CTA
  label/URL; audience picker (all / regulars N+ orders / lapsed N+ days) with **live recipient
  count**; preview (render the Blade template server-side, show in a sandboxed iframe via
  `srcdoc`); **Send test to self** (to the logged-in admin's email, marked so it never touches
  counters/recipients); send now / schedule for later.
- `Show.vue` — per-campaign report: status, counters, audience description, sent_at; cancel
  button while scheduled.
- Sidebar entry next to Customers (admin-gated, Manage group).

**Demo script (write into the session's wrap-up):** create 2–3 test customers with plus-addressed
real emails (`taylor+alice@…`) → order at the testaurant checking the marketing opt-in box →
run a **local** queue worker → compose "Slow Tuesday" campaign → send test to self → send → open
the inboxes; click an unsubscribe link to show one-click opt-out.

**Tests:** cross-restaurant scoping (A cannot see/send B's campaigns), staff-role forbidden,
status transitions (draft→scheduled→cancelled; cannot send a sent campaign), live count matches
audience resolver, test-send creates no recipient rows, empty-state count correct.

**Effort:** ~1 session.

---

## Session 3 — Safety rails (required before any real restaurant sends)

- **Resend webhook controller** (`routes/webhooks.php` + CSRF exemption; `StripeWebhookController`
  is the pattern). Verify signatures per current Resend docs (Svix-style headers; secret via env).
  Handle `email.bounced` (hard bounce → insert `suppressed_emails` global row + mark recipient),
  `email.complained` (suppress + **auto opt-out** that restaurant via
  `MarketingConsentService::optOutEmail(..., source: admin)` + mark recipient + count toward
  complaint rate), `email.delivered` (increment delivered). Match recipients by
  `resend_message_id`. Ops: register the webhook endpoint + secret in the Resend dashboard (prod).
- **Auto-pause:** complaint rate > 0.3% on a campaign, or ≥2 complaints while N is small → halt
  mid-send (remaining batches abort on the status guard) + campaign and restaurant sending
  `paused_by_platform` pending super-admin review.
- **First-campaign review queue** (locked: yes): each restaurant's first campaign gets status
  `pending_review` on send/schedule instead of dispatching. Super-admin page on the admin host
  (`routes/super-admin.php` conventions) listing pending campaigns with preview + approve/reject;
  email ping to the platform on submission. After one clean send (delivered, no pause), the
  restaurant is auto-approved and skips the queue.
  - **Amended 2026-08-26: review is automated via the Claude API** (`CampaignContentReviewer`,
    schema-constrained verdict; model/spot-check rate in `platform.campaigns.review.*`). A held
    campaign is reviewed by the `ReviewCampaign` job: approve → dispatches automatically (~1 min);
    flag / refusal / API failure / no key → stays `pending_review` for the human console (fail
    closed) + the email ping, with Claude's reasoning shown on the super page. Scope: every first
    campaign, plus a spot-check chance (default 10%) on each post-graduation submission.
- Caps re-enforced in the job layer; sending gate (approved/active/Stripe-ready) re-checked in
  `SendCampaign`.
- **Tests:** webhook → suppression + opt-out + counters, signature rejection, auto-pause halts
  remaining batches, review-queue flow (first campaign held, approval dispatches, second campaign
  skips queue), paused restaurant cannot send.

**Effort:** ~1 session. Plus one-time ops: webhook registration, Google Postmaster Tools on
`platefuloffers.fyi`, and tightening DMARC from `p=none` after sending is established.

---

## Session 4 — Per-restaurant custom sending domain (build only after 1–3 are proven live)

Decided 2026-08-25: shared domain is the default; a restaurant may configure its own sending
domain. You can only send as a domain you can prove, so this is a verification flow, not a text
field:

- Data: `sending_domain`, `sending_from_local_part`, `sending_domain_status`
  (pending|verified|failed) + `resend_domain_id` on `restaurants` (or a small side table).
- Flow: restaurant enters domain + desired from-address in the **onboarding wizard, same step as
  the custom site-domain question** ("what email do you want offers to come from?") — mirror the
  existing custom-domain request flow in `OnboardingController`. Create the domain via the Resend
  API, show the returned DNS records in the wizard, poll/re-check verification (button + on page
  load). Until verified, sending silently uses the shared domain.
- Send path: `CampaignMailer` picks the verified identity per restaurant; falls back to shared.
- Also editable post-onboarding (Settings), since most restaurants will configure it later if ever.
- Tests: unverified → shared identity; verified → custom identity; verification status
  transitions; wizard step optional/skippable.

**Effort:** ~1 session.

---

## Out of scope (backlog — see campaigns_plan.md)

SMS (Phase 4, demand-gated behind TCPA consent + 10DLC), automated win-back nudges, guest-consent
capture, click-through tracking, loyalty tie-ins.
