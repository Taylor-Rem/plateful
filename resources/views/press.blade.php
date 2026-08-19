<x-marketing.layout
    title="Press & Media | Plateful"
    meta-description="Press resources for Plateful — the Utah-built, founder-led online-ordering platform charging independent restaurants a flat 4%. Founder bio, citable data on Utah's restaurant ordering gap, brand assets, and contact."
    :canonical-url="route('press')"
>
    <section class="relative overflow-hidden">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-32 -left-24 h-96 w-96 rounded-full bg-teal-100/70 blur-3xl"></div>
            <div class="absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-crimson-100/40 blur-3xl"></div>
        </div>

        <div class="relative mx-auto max-w-4xl px-6 pt-16 pb-12 sm:pt-24 sm:pb-16">
            <p class="text-sm font-semibold tracking-widest text-crimson-600 uppercase">Press &amp; media</p>
            <h1 class="mt-4 text-4xl leading-[1.1] font-bold tracking-tight text-stone-900 sm:text-5xl">
                Covering Plateful
            </h1>
            <p class="mt-5 max-w-2xl text-lg leading-relaxed text-stone-600">
                Everything here may be quoted or republished with attribution. For interviews,
                data, or anything missing from this page:
                <a href="mailto:founder@plateful.fyi" class="font-semibold text-teal-700 underline-offset-4 hover:underline">founder@plateful.fyi</a>
                — replies typically same day.
            </p>
        </div>
    </section>

    <section class="border-t border-stone-900/5 bg-white py-14 sm:py-16">
        <div class="mx-auto max-w-4xl space-y-14 px-6">
            {{-- The founder story, quotable --}}
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-stone-900">The short version</h2>
                <p class="mt-4 leading-relaxed text-stone-600">
                    Plateful began when founder Taylor Remund, a software developer in American
                    Fork, tried to order dinner from a well-reviewed local restaurant and found
                    it had no website — no menu, no prices, no way to order. Curious whether that
                    was the exception, Remund catalogued more than 900 restaurants along the
                    Wasatch Front and found that more than half of Utah's independent restaurants
                    have no online-ordering channel of their own; their online presence is
                    whatever the delivery apps — which charge 15–30% commissions — choose to show.
                    Built nights and weekends, Plateful gives each restaurant its own branded
                    ordering storefront: customers pay the restaurant directly, the restaurant
                    keeps its own customer relationships, and Plateful charges a flat 4% of the
                    food subtotal, capped at $399 a month. Setup is free — Remund builds each
                    restaurant's menu and storefront himself.
                </p>
                <p class="mt-3 text-sm text-stone-500">
                    The long version: <a href="{{ route('stories.show', 'why-im-building-plateful') }}" class="font-semibold text-teal-700 underline-offset-4 hover:underline">Why I'm building Plateful</a>.
                </p>
            </div>

            {{-- Fast facts --}}
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-stone-900">Fast facts</h2>
                <dl class="mt-6 grid gap-x-10 gap-y-4 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-4 border-b border-stone-100 pb-3 sm:block sm:border-0 sm:pb-0">
                        <dt class="font-semibold text-stone-900">What it is</dt>
                        <dd class="text-right text-stone-600 sm:mt-1 sm:text-left">Branded online-ordering storefronts for independent restaurants</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-stone-100 pb-3 sm:block sm:border-0 sm:pb-0">
                        <dt class="font-semibold text-stone-900">Pricing</dt>
                        <dd class="text-right text-stone-600 sm:mt-1 sm:text-left">Flat 4% of the food subtotal (tax &amp; tips excluded), capped at $399/month; free setup</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-stone-100 pb-3 sm:block sm:border-0 sm:pb-0">
                        <dt class="font-semibold text-stone-900">Founded</dt>
                        <dd class="text-right text-stone-600 sm:mt-1 sm:text-left">2026, American Fork, Utah — Plateful LLC, founder-led and bootstrapped</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-stone-100 pb-3 sm:block sm:border-0 sm:pb-0">
                        <dt class="font-semibold text-stone-900">Status</dt>
                        <dd class="text-right text-stone-600 sm:mt-1 sm:text-left">Live and processing real payments (Stripe) since August 2026</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-stone-100 pb-3 sm:block sm:border-0 sm:pb-0">
                        <dt class="font-semibold text-stone-900">How money moves</dt>
                        <dd class="text-right text-stone-600 sm:mt-1 sm:text-left">Customers pay the restaurant directly; funds settle to the restaurant's own account — Plateful never holds the money</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-stone-100 pb-3 sm:block sm:border-0 sm:pb-0">
                        <dt class="font-semibold text-stone-900">Founder</dt>
                        <dd class="text-right text-stone-600 sm:mt-1 sm:text-left">Taylor Remund — full-stack software developer; built and runs Plateful solo</dd>
                    </div>
                </dl>
            </div>

            {{-- The data --}}
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-stone-900">Citable data: Utah's ordering gap</h2>
                <p class="mt-4 leading-relaxed text-stone-600">
                    In July 2026, Plateful catalogued 852 independent ($–$$) restaurants across 50
                    Wasatch Front cities and checked every website by hand — then, in August,
                    verified every restaurant against the delivery platforms themselves (DoorDash,
                    Uber Eats, Grubhub, and hosted-ordering pages). Headline findings — quotable
                    with attribution to "Plateful analysis":
                </p>
                <ul class="mt-5 space-y-3 text-stone-600">
                    <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-600"></span><span><strong class="text-stone-900">57% (486 of 852)</strong> have no online-ordering channel of their own.</span></li>
                    <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-600"></span><span><strong class="text-stone-900">309</strong> sell online only through marketplace listings at 15–30% commission; <strong class="text-stone-900">160</strong> run "their own" ordering on DoorDash-hosted Storefront pages — four times what websites alone reveal.</span></li>
                    <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-600"></span><span>Only <strong class="text-stone-900">17 restaurants</strong> have no online ordering anywhere — the gap is about ownership, not demand.</span></li>
                    <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-600"></span><span>The gap skews down-market: <strong class="text-stone-900">72% of budget ($) independents</strong> have no channel of their own, versus 47% of mid-range ($$).</span></li>
                    <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-600"></span><span>These are established businesses: the no-channel group has a <strong class="text-stone-900">median of 843 Google reviews</strong> at a 4.4-star average.</span></li>
                </ul>
                <p class="mt-4 text-sm text-stone-500">
                    Full findings, charts, and methodology:
                    <a href="{{ route('stories.show', 'the-online-ordering-gap') }}" class="font-semibold text-teal-700 underline-offset-4 hover:underline">the data story</a>.
                    City-level and vendor-level cuts available on request.
                </p>
            </div>

            {{-- Story angles --}}
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-stone-900">Three story angles</h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-2xl bg-cream/70 p-6 ring-1 ring-stone-900/5">
                        <h3 class="font-semibold text-stone-900">The founder</h3>
                        <p class="mt-2 text-sm leading-relaxed text-stone-600">A Utah developer builds a flat-4% alternative to 30% delivery apps — solo, nights and weekends, selling door to door.</p>
                    </div>
                    <div class="rounded-2xl bg-cream/70 p-6 ring-1 ring-stone-900/5">
                        <h3 class="font-semibold text-stone-900">The data</h3>
                        <p class="mt-2 text-sm leading-relaxed text-stone-600">More than half of Utah's independent restaurants can't take an online order of their own — first-of-its-kind local data, city by city.</p>
                    </div>
                    <div class="rounded-2xl bg-cream/70 p-6 ring-1 ring-stone-900/5">
                        <h3 class="font-semibold text-stone-900">The economics</h3>
                        <p class="mt-2 text-sm leading-relaxed text-stone-600">What delivery apps actually cost a Utah restaurant, walked through line by line — commissions, customer data, and who keeps the regulars.</p>
                    </div>
                </div>
            </div>

            {{-- Press kit --}}
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-stone-900">Brand assets</h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div class="flex items-center justify-between gap-4 rounded-2xl bg-cream/70 p-6 ring-1 ring-stone-900/5">
                        <div>
                            <h3 class="font-semibold text-stone-900">Wordmark</h3>
                            <p class="mt-1 text-sm text-stone-600">Full "plateful" logotype, SVG</p>
                        </div>
                        <a href="/plateful-logo.svg" download class="rounded-full border border-stone-900/10 bg-white px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-900/20">Download</a>
                    </div>
                    <div class="flex items-center justify-between gap-4 rounded-2xl bg-cream/70 p-6 ring-1 ring-stone-900/5">
                        <div>
                            <h3 class="font-semibold text-stone-900">Logo mark</h3>
                            <p class="mt-1 text-sm text-stone-600">Plate-and-fork mark, SVG</p>
                        </div>
                        <a href="/plateful-logo-mark.svg" download class="rounded-full border border-stone-900/10 bg-white px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-900/20">Download</a>
                    </div>
                </div>
                <p class="mt-4 text-sm text-stone-500">
                    Founder headshot and product screenshots available on request at
                    <a href="mailto:founder@plateful.fyi" class="font-semibold text-teal-700 underline-offset-4 hover:underline">founder@plateful.fyi</a>.
                </p>
            </div>

            {{-- Contact --}}
            <div class="rounded-2xl bg-teal-950 p-8 text-teal-100/80">
                <h2 class="text-xl font-bold tracking-tight text-white">Media contact</h2>
                <p class="mt-3 text-sm leading-relaxed">
                    Taylor Remund, founder —
                    <a href="mailto:founder@plateful.fyi" class="font-semibold text-white underline-offset-4 hover:underline">founder@plateful.fyi</a>.
                    Based in American Fork; available for interviews in person along the Wasatch
                    Front or by video, usually within a day or two.
                </p>
            </div>
        </div>
    </section>
</x-marketing.layout>
