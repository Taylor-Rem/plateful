---
title: "We checked 855 Utah restaurants. Half can't take an online order."
date: '2026-08-14'
excerpt: "I catalogued the independent restaurants of the Wasatch Front — 855 of them, across 50 cities. More than half have no way to take an online order of their own. Here's the data."
author: 'Taylor Remund, founder of Plateful'
tags: [data]
published: true
hero: '/images/stories/ordering-gap-hero.png'
---

While I was building Plateful, I did something a little obsessive: I catalogued
every independent restaurant I could find along the Wasatch Front. Not a sample —
the whole corridor, from Ogden down through Salt Lake to Provo, fifty cities'
worth of taquerias, pho shops, curry houses, and diners.

I wanted to answer one question with actual data instead of a hunch: **how many
of Utah's independent restaurants can take an online order without renting that
ability from someone else?**

## What I did

Using Google's Places data, I searched cuisine by cuisine, city by city, for
budget and mid-range ($ and $$) restaurants across the Wasatch Front. That
produced 965 restaurants. I removed the 110 locations belonging to chains
(any name appearing at three or more addresses), leaving **855 independents**.
Then, in July 2026, I checked every one of their websites — including the
JavaScript-rendered ones a simple scrape would miss — and recorded how, if at
all, each restaurant takes orders online.

## The finding

**453 of the 855 — 53% — have no online-ordering channel of their own.**

![How 855 Wasatch Front independents take (or don't take) online orders](/images/stories/ordering-gap-breakdown.svg)

The breakdown: 341 restaurants (40%) have a website with no way to order on it.
Another 58 (7%) have no website at all — no menu, no prices, no way to know
what they serve without calling or driving over. And 54 (6%) have a website
whose only ordering option is a link that hands you to DoorDash or Uber Eats —
which means every "online order" those restaurants receive costs them a 15–30%
commission.

On the other side, 345 restaurants (40%) run their own online ordering through
some first-party vendor — Toast is the biggest at 134, followed by Olo, Square,
and Clover. Another 39 use DoorDash Storefront, which is its own interesting
category: ordering that looks first-party but lives inside the delivery
platform's ecosystem.

Here's the part that surprised me most: **these are not struggling businesses.**
The restaurants with no direct ordering channel have a *median* of 846 Google
reviews, 96% of them have more than a hundred, and the group averages a 4.4-star
rating. These are beloved, busy, established places. They're just invisible the
moment you pick up your phone hungry.

## The cheaper the restaurant, the bigger the gap

![Share with no direct online-ordering channel, by price level](/images/stories/ordering-gap-price.svg)

Among budget ($) restaurants, 66% have no direct channel, versus 44% of
mid-range ($$) spots. And look at what dominates the dataset: Mexican (178
restaurants), Chinese, Thai, Vietnamese, Indian, Korean. The Wasatch Front's
ordering gap is concentrated in exactly the kitchens least likely to have
someone with spare evenings to fight with web software — the family-run places
where everyone already works the line.

## What the gap costs

Delivery-app commissions on marketplace orders run 15–30%. A restaurant doing
$6,000 a month through the apps at 25% pays $1,500 a month — $18,000 a year.

Scaling that across the corridor requires assumptions, so here are mine,
conservatively: if only a third of the 453 no-channel restaurants actively sell
through delivery apps, at a modest $3,000 a month each, commissions take about
**$1.4 million a year** from this one stretch of Utah. If it's half of them at
$5,000 a month — still unremarkable numbers — it's over **$3 million a year**.
Either way: millions, annually, from independent kitchens along a single metro
corridor.

To be fair to the apps: they do bring new customers, and that's genuinely worth
paying for. The problem is the *regulars* — the customer who orders from you
every week and would happily order direct if you had a direct. Paying 25% to a
marketplace for a customer it didn't bring you is the expensive part.

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

*Methodology: 965 restaurants collected via Google Places text search
(cuisine × city, $–$$ price levels, 50 Wasatch Front localities), July 2026.
Chains excluded by name frequency (3+ locations), leaving 855 independents.
Ordering channels detected from each restaurant's own website, including
JavaScript-rendered pages; 18 sites were unreachable and are counted in the
total but not classified. Classification can err on edge cases — if your
restaurant is listed wrong, email me and I'll correct it.*
