<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { create as createSignup } from '@/actions/App/Http/Controllers/OwnerSignupController';
import { home } from '@/routes';

const features = [
    {
        title: 'Your own storefront',
        description:
            'A branded ordering site on a Plateful subdomain — or your own custom domain. Diners place orders, you fulfil them.',
    },
    {
        title: 'Menu, hours, modifiers',
        description:
            'Manage everything from one admin. Reuse templates, set per-day hours, and disable items in seconds.',
    },
    {
        title: 'Loyalty out of the box',
        description:
            'Customers earn points on every completed order — no integrations, no extra fees.',
    },
    {
        title: 'Stripe Connect',
        description:
            'Get paid directly. Plateful never touches your money — Stripe sends funds straight to your account.',
    },
    {
        title: 'Pickup + delivery',
        description:
            'Self-deliver, partner with a third-party provider, or stick to pickup. Switch any time.',
    },
    {
        title: 'Built for multi-staff',
        description:
            'Invite managers and staff with the right scope. Plateful accounts work across every restaurant on the platform.',
    },
];

const steps = [
    {
        number: '01',
        title: 'Sign up',
        description:
            'Tell us about your restaurant and pick a subdomain. Takes about two minutes.',
    },
    {
        number: '02',
        title: 'We review',
        description:
            'A real human reviews your application within one business day. We email when you’re approved.',
    },
    {
        number: '03',
        title: 'Build and go live',
        description:
            'Add your menu, set hours, connect Stripe, and flip the switch. Your storefront goes live the same day.',
    },
];

// What a single $25 order costs the restaurant on each service.
const pricingRows: { service: string; cost: string; note: string; highlight?: boolean }[] = [
    { service: 'DoorDash', cost: '$7.50', note: '~30% commission' },
    { service: 'GloriaFood', cost: '$0.38–$0.50', note: 'card-processing add-ons' },
    { service: 'ChowNow', cost: '$0', note: '+ $150/mo flat' },
    { service: 'Plateful', cost: '$0.25', note: '1% — that’s it', highlight: true },
];
</script>

<template>
    <Head title="Plateful for Restaurants" />

    <div class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <header class="border-b border-black/5 dark:border-white/10">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
                <Link :href="home()" class="flex items-center gap-2">
                    <AppLogoIcon class-name="h-7 w-7 text-[#f53003] dark:text-[#FF4433]" />
                    <span class="text-lg font-semibold tracking-tight">Plateful</span>
                </Link>

                <nav class="flex items-center gap-2 text-sm">
                    <a href="#features" class="hidden px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] sm:inline-block dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]">Features</a>
                    <a href="#pricing" class="hidden px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] sm:inline-block dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]">Pricing</a>
                    <a href="#how-it-works" class="hidden px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] sm:inline-block dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]">How it works</a>
                    <Link
                        :href="createSignup()"
                        class="rounded-md bg-[#f53003] px-4 py-1.5 text-white hover:bg-[#d62a02] dark:bg-[#FF4433] dark:hover:bg-[#e63b2c]"
                    >
                        Get started
                    </Link>
                </nav>
            </div>
        </header>

        <main>
            <section class="mx-auto max-w-6xl px-6 py-20 sm:py-28">
                <div class="grid items-center gap-12 lg:grid-cols-2">
                    <div>
                        <p class="mb-3 text-sm font-medium uppercase tracking-wide text-[#f53003] dark:text-[#FF4433]">For restaurants</p>
                        <h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">
                            1% per order. That's it.
                        </h1>
                        <p class="mt-5 max-w-xl text-lg text-[#1b1b18]/70 dark:text-[#EDEDEC]/70">
                            No subscription, no tiers, no minimums. You only pay when you make money. Plateful gives your restaurant a branded storefront, menu management, loyalty, and Stripe-backed checkout — go live this week.
                        </p>
                        <div class="mt-8 flex flex-wrap gap-3">
                            <Link
                                :href="createSignup()"
                                class="rounded-md bg-[#f53003] px-5 py-2.5 text-sm font-medium text-white hover:bg-[#d62a02] dark:bg-[#FF4433] dark:hover:bg-[#e63b2c]"
                                data-test="cta-get-started"
                            >
                                Start your application
                            </Link>
                            <a href="#features" class="rounded-md border border-[#19140035] px-5 py-2.5 text-sm font-medium hover:bg-black/5 dark:border-[#3E3E3A] dark:hover:bg-white/5">
                                See features
                            </a>
                        </div>
                    </div>
                    <div class="rounded-xl border border-black/5 bg-white p-8 shadow-sm dark:border-white/10 dark:bg-white/5">
                        <p class="text-sm font-medium uppercase tracking-wide text-[#1b1b18]/60 dark:text-[#EDEDEC]/60">What you get</p>
                        <ul class="mt-4 space-y-3 text-sm">
                            <li>• <strong>1% per order</strong> — no subscription</li>
                            <li>• A branded storefront on <code>your-name.plateful.fyi</code></li>
                            <li>• Custom-domain support (we set it up for you)</li>
                            <li>• Menu, hours, modifiers, and templates</li>
                            <li>• Stripe Connect — payments go straight to you</li>
                            <li>• Pickup, delivery, or both</li>
                            <li>• Loyalty points, automatically</li>
                        </ul>
                    </div>
                </div>
            </section>

            <section id="features" class="border-y border-black/5 bg-black/[0.02] py-20 dark:border-white/10 dark:bg-white/[0.02]">
                <div class="mx-auto max-w-6xl px-6">
                    <h2 class="text-3xl font-semibold tracking-tight">Everything you need to take orders online</h2>
                    <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="feature in features"
                            :key="feature.title"
                            class="rounded-lg border border-black/5 bg-white p-6 dark:border-white/10 dark:bg-white/5"
                        >
                            <h3 class="text-base font-semibold">{{ feature.title }}</h3>
                            <p class="mt-2 text-sm text-[#1b1b18]/70 dark:text-[#EDEDEC]/70">{{ feature.description }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="pricing" class="mx-auto max-w-6xl px-6 py-20">
                <div class="max-w-2xl">
                    <h2 class="text-3xl font-semibold tracking-tight">1% per order. That's it.</h2>
                    <p class="mt-4 text-[#1b1b18]/70 dark:text-[#EDEDEC]/70">
                        We charge 1% of the food subtotal — not tax, not tips, not delivery fees. No monthly
                        fee, no tiers, no minimums. Just a simple cut when you make a sale.
                    </p>
                </div>

                <div class="mt-10 overflow-hidden rounded-xl border border-black/5 dark:border-white/10">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-black/[0.02] text-xs uppercase tracking-wide text-[#1b1b18]/60 dark:bg-white/[0.02] dark:text-[#EDEDEC]/60">
                            <tr>
                                <th class="px-6 py-3 font-medium">Service</th>
                                <th class="px-6 py-3 font-medium">Cost on a $25 order</th>
                                <th class="px-6 py-3 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in pricingRows"
                                :key="row.service"
                                class="border-t border-black/5 dark:border-white/10"
                                :class="row.highlight ? 'bg-[#f53003]/[0.06] dark:bg-[#FF4433]/[0.08]' : ''"
                            >
                                <td class="px-6 py-3 font-medium" :class="row.highlight ? 'text-[#f53003] dark:text-[#FF4433]' : ''">
                                    {{ row.service }}
                                </td>
                                <td class="px-6 py-3 font-semibold">{{ row.cost }}</td>
                                <td class="px-6 py-3 text-[#1b1b18]/60 dark:text-[#EDEDEC]/60">{{ row.note }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-4 text-xs text-[#1b1b18]/50 dark:text-[#EDEDEC]/50">
                    Comparison is illustrative; third-party pricing varies by plan and region. Stripe's standard
                    processing fees apply on every platform.
                </p>
            </section>

            <section id="how-it-works" class="mx-auto max-w-6xl px-6 py-20">
                <h2 class="text-3xl font-semibold tracking-tight">How it works</h2>
                <div class="mt-10 grid gap-8 sm:grid-cols-3">
                    <div v-for="step in steps" :key="step.number">
                        <p class="text-sm font-mono text-[#f53003] dark:text-[#FF4433]">{{ step.number }}</p>
                        <h3 class="mt-2 text-lg font-semibold">{{ step.title }}</h3>
                        <p class="mt-2 text-sm text-[#1b1b18]/70 dark:text-[#EDEDEC]/70">{{ step.description }}</p>
                    </div>
                </div>
            </section>

            <section class="border-t border-black/5 py-20 dark:border-white/10">
                <div class="mx-auto flex max-w-3xl flex-col items-center px-6 text-center">
                    <h2 class="text-3xl font-semibold tracking-tight">Ready to put your restaurant on Plateful?</h2>
                    <p class="mt-4 text-[#1b1b18]/70 dark:text-[#EDEDEC]/70">No card required to apply. Start free, pay only when you make money.</p>
                    <Link
                        :href="createSignup()"
                        class="mt-8 rounded-md bg-[#f53003] px-6 py-3 text-sm font-medium text-white hover:bg-[#d62a02] dark:bg-[#FF4433] dark:hover:bg-[#e63b2c]"
                    >
                        Start your application
                    </Link>
                </div>
            </section>
        </main>

        <footer class="border-t border-black/5 py-8 dark:border-white/10">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 text-sm text-[#1b1b18]/60 dark:text-[#EDEDEC]/60">
                <span>© Plateful</span>
                <Link :href="home()" class="hover:underline">Diner home</Link>
            </div>
        </footer>
    </div>
</template>
