---
title: "DoorDash Storefront looks like your own online ordering. Read the fine print."
date: '2026-08-17'
excerpt: "It's commission-free, it looks like the restaurant's own ordering, and 160 Wasatch Front independents run on it — four times what their websites reveal. But whose channel is it? I read the merchant terms so you don't have to."
author: 'Taylor Remund, founder of Plateful'
tags: [explainer, data]
published: false
hero: '/images/stories/doordash-storefront-hero.png'
---

<!--
DRAFT NOTES — verify before publishing:
1. Processing rates (2.9% + 30¢ on Boost/Pro, 3.3% + 30¢ on Starter) come from
   third-party 2026 fee guides (getsauce.com, restolabs.com), not DoorDash's own
   pricing page, which says "commission-free but do carry a payment processing
   fee" without a number. Sanity-check against a real merchant agreement if you
   can get one from an owner.
2. The addendum quotes below are from the current US Storefront Product
   Addendum (help.doordash.com, retrieved 2026-08-17). Re-check before publish.
3. Storefront counts updated 2026-08-19 from the platform-verification pass:
   39 were visible from websites alone; 160 independents total have an
   order.online store page (channel_class='storefront' in wasatch.db).
-->

When I catalogued the Wasatch Front's independent restaurants for
[the ordering-gap piece](/stories/the-online-ordering-gap), one category kept
making me look twice: restaurants whose ordering *looked* first-party — an
"Order Online" button on their own website, their own menu, their own
branding — but whose button went to a page DoorDash runs, on a domain DoorDash
owns. Checking websites alone, I found 39 of them. Then I verified every
restaurant against the platforms directly, and the real number is **160 —
nearly one in five of the corridor's independents**, four times what the
websites reveal. It is quietly the second-largest "own ordering" arrangement
in Utah, ahead of everything except Toast.

That's DoorDash Storefront — these days marketed as "Online Ordering by
DoorDash" — and it's the most interesting product in this whole space, because
it's DoorDash's answer to the exact complaint I built Plateful around: *the
apps take too much*. So let's take it seriously.

## The pitch, and it's a real pitch

Storefront is commission-free. Not reduced-commission — zero. Orders placed
through it skip the 15–30% marketplace commission entirely; the restaurant pays
a payment-processing fee of roughly 2.9% plus 30¢ per order (3.3% on the entry
package). Setup is fast, the checkout is one customers already know, and if
you're currently paying 25% on every marketplace order, moving your regulars to
Storefront is an enormous, immediate raise.

I want to be plainly honest here: **on raw per-order cost, a Storefront pickup
order is cheaper than Plateful.** Plateful charges 4% of the food subtotal on
top of standard Stripe processing. If per-order price were the only thing that
mattered, Storefront would win that comparison, and I'd rather tell you that
myself than have a DoorDash rep tell you I didn't.

So why am I writing this? Because "commission-free" is the answer to one
question — *what does an order cost?* — and it's the wrong question to stop at.
The right one is: *whose channel is this?*

## Catch one: the customers aren't yours

This is the one that matters most, and it's not my characterization — it's the
contract. DoorDash's Storefront merchant terms say the restaurant receives
"sufficient information to prepare the order" but "will not own such Customer
Data," and they bar the restaurant from using it for remarketing.

Read that again in plain English: a regular can order from you every Friday
for a year through the ordering page on *your own website*, and you don't own
their relationship and can't market to them. No email list, no "we miss you"
offer, no loyalty program of your own. The thing that makes a direct channel
worth having — the compounding value of customers you can reach — is
specifically carved out.

## Catch two: the address isn't yours

Every Storefront page I found in the dataset lives on DoorDash's ordering
domain, not the restaurant's. Your website links out to it, the way you'd link
to anything you don't control.

That sounds cosmetic. For search, it isn't. When someone Googles your
restaurant's name plus "order," the page that ranks, collects the click, and
builds authority over the years is DoorDash's. Menus, hours, ordering — the
content that search engines treat as proof your restaurant is real and active —
accrues to their domain. If you ever leave, you take none of it with you; the
"order online" page for your restaurant simply stops existing.

## Catch three: the terms are theirs

The marketing page says commission-free with no monthly fee, and today that's
broadly true. But the contract behind it reserves a fuller fee schedule — a
setup fee, a software fee, a per-order merchant fee, merchant delivery fees —
whatever your particular agreement specifies. Delivery on Storefront orders is
fulfilled by Dashers, with a delivery fee and service fee on your customer's
side of the receipt, plus a $2 small-order fee under $10. And Storefront rides
on your DoorDash partnership: the pricing, the terms, and the product's
existence are decisions DoorDash makes, on DoorDash's schedule.

None of that is a scandal. It's just renting. Renting is fine — every
restaurant rents *something* — as long as nobody tells you it's ownership.

## The honest comparison

If your situation is "we're paying 25% on every online order and we have no
website," Storefront is genuinely one of the better moves available to you,
and I'd tell you that to your face.

What Plateful sells is the thing Storefront's contract carves out. Your
storefront runs on your own domain, so every search click builds your asset.
Your customers are *your* customers — names, emails, order history, a loyalty
program that belongs to you. Payment goes straight from the diner's card to
your bank account through Stripe; Plateful never holds your money. That's what
the 4% is for — and tips and tax are excluded, there's no subscription, and I
build the menu and storefront for you.

Cheaper rent, or a mortgage. That's the actual choice, and it deserves to be
made with the fine print on the table.

If you want to see the numbers side by side for your restaurant, the
[savings calculator](/savings) does the math with your volume, or
[grab 15 minutes with me](/book) and we'll read your current statement
together.

---

*Sources: DoorDash merchant pricing page and the US Storefront Product
Addendum (DoorDash Help Center), both retrieved August 2026; processing rates
as reported in current third-party fee guides. Storefront usage counts from my
verified catalogue of 852 Wasatch Front independents (websites checked July
2026, platforms verified August 2026). If DoorDash's terms
change or I've misread a clause, email me and I'll correct it.*
