---
title: "We checked 852 Utah restaurants — twice. 57% can't take an online order of their own."
date: '2026-08-14'
excerpt: "I catalogued the independent restaurants of the Wasatch Front, checked every website — then went back and checked every delivery platform too. 57% have no online ordering of their own, and only 17 have none at all. Here's the data."
author: 'Taylor Remund, founder of Plateful'
tags: [data]
published: true
hero: '/images/stories/ordering-gap-hero.png'
---

*Updated August 19, 2026: this analysis originally relied on checking each
restaurant's website. I've since verified every restaurant against the
delivery platforms themselves — DoorDash, Uber Eats, Grubhub, order.online,
and Toast-hosted pages — plus a full rendered-browser recheck of every site,
and caught three chains that had slipped my independent filter. The numbers
below are the corrected ones, and the story they tell is sharper than the
original. The old version's headline claim survives; most of its
sub-numbers don't. Details in the methodology note at the bottom.*

While I was building Plateful, I did something a little obsessive: I catalogued
every independent restaurant I could find along the Wasatch Front. Not a sample —
the whole corridor, from Ogden down through Salt Lake to Provo, fifty cities'
worth of taquerias, pho shops, curry houses, and diners.

I wanted to answer one question with actual data instead of a hunch: **how many
of Utah's independent restaurants can take an online order without renting that
ability from someone else?**

## What I did

Using Google's Places data, I searched cuisine by cuisine, city by city, for
budget and mid-range ($ and $$) restaurants across the Wasatch Front. After
removing chains, that left **852 independents**. In July 2026 I checked every
one of their websites — including the JavaScript-rendered ones a simple scrape
would miss. Then in August I did what a website check can't: I searched every
restaurant against the delivery platforms themselves, because a restaurant
with no website can still be listed on DoorDash, and a restaurant's "own"
ordering page can secretly live on DoorDash's domain.

## The finding

**486 of the 852 — 57% — have no online-ordering channel of their own.**

![How 852 Wasatch Front independents take (or don't take) online orders](/images/stories/ordering-gap-breakdown.svg)

The breakdown: 309 restaurants (36%) are **marketplace-only** — their only
online ordering is a DoorDash, Uber Eats, or Grubhub listing, at 15–30%
commission per order. Another 160 (19%) run what looks like their own
ordering but is actually **DoorDash Storefront** — hosted on DoorDash's
domain, under [DoorDash's terms](/stories/doordash-storefront), with DoorDash
keeping the customer data. And 17 (2%) have **no online ordering anywhere** —
verified against every platform.

On the other side, 366 restaurants (43%) genuinely run their own first-party
ordering — Toast is the biggest at 154 installs, followed by Olo, Square, and
Clover. And 58 independents still have no website at all.

Two things in that breakdown surprised me. First, **online demand is nearly
universal**: only 17 of 852 restaurants are truly offline. The gap was never
about whether these kitchens sell online — they do — it's about *who owns the
channel*. Second, **DoorDash's reach is four times bigger than websites
reveal**: my July website check found 39 restaurants on Storefront; the
platform check found 160. The most invisible commission structure on the
Front is the one that looks like independence.

Here's the part that stuck with me most: **these are not struggling
businesses.** The restaurants with no channel of their own have a *median* of
843 Google reviews, 96% of them have more than a hundred, and the group
averages a 4.4-star rating. These are beloved, busy, established places.
They're just paying rent, one order at a time, on customers they earned
themselves.

## The cheaper the restaurant, the bigger the gap

![Share with no direct online-ordering channel, by price level](/images/stories/ordering-gap-price.svg)

Among budget ($) restaurants, **72%** have no channel of their own, versus
**47%** of mid-range ($$) spots. Budget kitchens are 39% of the corridor but
only 26% of first-party-ordering adopters. And look at what dominates the
dataset: Mexican (the largest group), Chinese, Thai, Vietnamese, Indian,
Korean. The Wasatch Front's ordering gap is concentrated in exactly the
kitchens least likely to have someone with spare evenings to fight with web
software — the family-run places where everyone already works the line.

## What the gap costs

Delivery-app commissions on marketplace orders run 15–30%. A restaurant doing
$6,000 a month through the apps at 25% pays $1,500 a month — $18,000 a year.

Scaling that requires assumptions, so here are mine, conservatively: the
platform check found **309 independents whose only online channel is a
marketplace listing**. If just half of them sell $4,000 a month through the
apps at 25%, commissions take about **$1.85 million a year** from this one
stretch of Utah. Either way: millions, annually, from independent kitchens
along a single metro corridor.

To be fair to the apps: they do bring new customers, and that's genuinely worth
paying for. The problem is the *regulars* — the customer who orders from you
every week and would happily order direct if you had a direct. Paying 25% to a
marketplace for a customer it didn't bring you is
[the expensive part](/stories/your-regulars-are-the-expensive-part).

## Why I care

Full disclosure, in case the byline didn't give it away: I'm not a neutral
observer. This data is *why* I built [Plateful](/for-restaurants) — online
ordering that belongs to the restaurant, at a flat 4% instead of 15–30%. I
started collecting these numbers as a hungry customer who couldn't find a menu,
and ended up with a spreadsheet I couldn't unsee.

If you own one of these restaurants, you can see what the gap costs *you*
specifically — plug your numbers into the
[savings calculator](/savings), or [grab 15 minutes with me](/book) and I'll
walk you through your own math.

---

*Methodology (v2, August 2026): 965 restaurants collected via Google Places
text search (cuisine × city, $–$$ price levels, 50 Wasatch Front localities),
July 2026. Chains excluded by name frequency (3+ locations) plus manual
flags, leaving 852 independents. Ordering channels detected in two passes:
(1) each restaurant's own website, first as raw HTML, then re-checked with a
fully rendered browser to catch JavaScript-injected ordering; (2) a
platform-verification pass searching every restaurant — by name, shortened
name, and phone number — against DoorDash, Uber Eats, Grubhub, order.online,
and Toast-hosted ordering pages. "No channel of their own" counts
marketplace-only listings and DoorDash-Storefront-hosted pages, per
[the Storefront analysis](/stories/doordash-storefront). Classification can
still err on edge cases — if your restaurant is listed wrong, email me and
I'll correct it.*
