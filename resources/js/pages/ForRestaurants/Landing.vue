<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Award,
    Bike,
    ClipboardList,
    CreditCard,
    Store,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { create as createSignup } from '@/actions/App/Http/Controllers/OwnerSignupController';
import MarketingLayout from '@/layouts/MarketingLayout.vue';

const props = defineProps<{
    authUserName: string | null;
    hasAdminAccess: boolean;
    adminUrl: string;
}>();

// Owners sign in on the admin host, so post-login `/` resolves to the admin
// console (not the diner home).
const signInUrl = computed(() => `${props.adminUrl}/login`);

const features = [
    {
        icon: Store,
        title: 'Your own storefront',
        description:
            'A branded ordering site on a Plateful subdomain — or your own custom domain. Diners place orders, you fulfil them.',
    },
    {
        icon: ClipboardList,
        title: 'Menu, hours, modifiers',
        description:
            'Manage everything from one admin. Reuse templates, set per-day hours, and disable items in seconds.',
    },
    {
        icon: Award,
        title: 'Loyalty out of the box',
        description:
            'Customers earn points on every completed order — no integrations, no extra fees.',
    },
    {
        icon: CreditCard,
        title: 'Stripe Connect',
        description:
            'Get paid directly. Plateful never touches your money — Stripe sends funds straight to your account.',
    },
    {
        icon: Bike,
        title: 'Pickup + delivery',
        description:
            'Self-deliver, partner with a third-party provider, or stick to pickup. Switch any time.',
    },
    {
        icon: Users,
        title: 'Built for multi-staff',
        description:
            'Invite managers and staff with the right scope. Plateful accounts work across every restaurant on the platform.',
    },
];

const steps = [
    {
        title: 'Sign up',
        description:
            'Tell us about your restaurant and pick a subdomain. Takes about two minutes — your account is ready instantly.',
    },
    {
        title: 'Set it up',
        description:
            'Add your menu — or start from a template — set your hours, and connect Stripe.',
    },
    {
        title: 'Go live',
        description:
            'Flip the switch and start taking orders. Your storefront can be live the same day.',
    },
];

// What a single $25 order costs the restaurant on each service, as a
// share of the most expensive option (drives the comparison bar widths).
const feeComparison: {
    service: string;
    cost: string;
    note: string;
    barWidth: string;
    highlight?: boolean;
}[] = [
    {
        service: 'DoorDash',
        cost: '$7.50',
        note: '~30% commission',
        barWidth: '100%',
    },
    {
        service: 'Uber Eats',
        cost: '$7.00',
        note: '~28% commission',
        barWidth: '93%',
    },
    {
        service: 'Plateful',
        cost: '$1.00',
        note: '4% — that’s it',
        barWidth: '13.5%',
        highlight: true,
    },
];
</script>

<template>
    <Head title="Plateful for Restaurants" />

    <MarketingLayout :admin-url="adminUrl">
        <template #nav>
            <a
                href="#features"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >Features</a
            >
            <a
                href="#pricing"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >Pricing</a
            >
            <a
                href="#how-it-works"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >How it works</a
            >
        </template>

        <template #actions>
            <template v-if="authUserName">
                <span
                    class="hidden px-2 text-stone-500 sm:inline-block"
                    data-test="nav-greeting"
                >
                    Hi, {{ authUserName }}
                </span>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900"
                    data-test="nav-sign-out"
                >
                    Sign out
                </Link>
                <a
                    v-if="hasAdminAccess"
                    :href="adminUrl"
                    class="ml-2 inline-flex items-center gap-1.5 rounded-full bg-teal-700 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-teal-800"
                    data-test="nav-admin-console"
                >
                    Admin console
                    <ArrowRight class="size-3.5" />
                </a>
                <Link
                    v-else
                    :href="createSignup()"
                    class="ml-2 inline-flex items-center rounded-full bg-teal-700 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-teal-800"
                >
                    Get started
                </Link>
            </template>
            <template v-else>
                <a
                    :href="signInUrl"
                    class="rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900"
                    data-test="nav-sign-in"
                >
                    Sign in
                </a>
                <Link
                    :href="createSignup()"
                    class="ml-2 inline-flex items-center rounded-full bg-teal-700 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-teal-800"
                >
                    Get started
                </Link>
            </template>
        </template>

        <!-- Hero -->
        <section class="relative overflow-hidden">
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0"
            >
                <div
                    class="absolute -top-32 -left-24 h-96 w-96 rounded-full bg-teal-100/70 blur-3xl"
                ></div>
                <div
                    class="absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-crimson-100/40 blur-3xl"
                ></div>
            </div>

            <div
                class="relative mx-auto max-w-6xl px-6 pt-20 pb-24 sm:pt-28 sm:pb-28"
            >
                <div class="grid items-center gap-14 lg:grid-cols-2">
                    <div>
                        <p
                            class="text-sm font-semibold tracking-widest text-crimson-600 uppercase"
                        >
                            For restaurants
                        </p>
                        <h1
                            class="mt-4 text-5xl leading-[1.05] font-bold tracking-tight text-stone-900 sm:text-6xl"
                        >
                            <span class="text-teal-700">4% per order.</span>
                            <br />
                            That's it.
                        </h1>
                        <p
                            class="mt-6 max-w-xl text-lg leading-relaxed text-stone-600"
                        >
                            No subscription, no tiers, no minimums. You only pay
                            when you make money. Plateful gives your restaurant
                            a branded storefront, menu management, loyalty, and
                            Stripe-backed checkout — go live this week.
                        </p>
                        <div class="mt-9 flex flex-wrap items-center gap-3">
                            <a
                                v-if="hasAdminAccess"
                                :href="adminUrl"
                                class="inline-flex items-center gap-2 rounded-full bg-teal-700 px-6 py-3 text-sm font-semibold text-white shadow-md shadow-teal-900/20 transition hover:bg-teal-800"
                                data-test="cta-dashboard"
                            >
                                Go to your dashboard
                                <ArrowRight class="size-4" />
                            </a>
                            <Link
                                v-else
                                :href="createSignup()"
                                class="inline-flex items-center gap-2 rounded-full bg-teal-700 px-6 py-3 text-sm font-semibold text-white shadow-md shadow-teal-900/20 transition hover:bg-teal-800"
                                data-test="cta-get-started"
                            >
                                Get started free
                                <ArrowRight class="size-4" />
                            </Link>
                            <a
                                href="#features"
                                class="inline-flex items-center rounded-full border border-stone-900/10 bg-white px-6 py-3 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-900/20 hover:text-stone-900"
                            >
                                See features
                            </a>
                        </div>
                        <p
                            v-if="!authUserName"
                            class="mt-4 text-sm text-stone-500"
                        >
                            Already have an account?
                            <a
                                :href="signInUrl"
                                class="font-semibold text-teal-700 underline-offset-4 hover:underline"
                                data-test="hero-sign-in"
                                >Sign in</a
                            >.
                        </p>
                    </div>

                    <!-- Fee comparison graphic -->
                    <div
                        class="rounded-3xl bg-white p-8 shadow-xl ring-1 shadow-stone-900/5 ring-stone-900/5"
                    >
                        <p
                            class="text-xs font-semibold tracking-wider text-stone-500 uppercase"
                        >
                            What a $25 order costs you
                        </p>

                        <div class="mt-6 space-y-5">
                            <div
                                v-for="row in feeComparison"
                                :key="row.service"
                            >
                                <div
                                    class="flex items-baseline justify-between text-sm"
                                >
                                    <span
                                        class="font-semibold"
                                        :class="
                                            row.highlight
                                                ? 'text-teal-700'
                                                : 'text-stone-700'
                                        "
                                        >{{ row.service }}</span
                                    >
                                    <span
                                        class="font-bold tabular-nums"
                                        :class="
                                            row.highlight
                                                ? 'text-teal-700'
                                                : 'text-stone-900'
                                        "
                                        >{{ row.cost }}</span
                                    >
                                </div>
                                <div
                                    class="mt-2 h-3 overflow-hidden rounded-full bg-stone-100"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="
                                            row.highlight
                                                ? 'bg-teal-600'
                                                : 'bg-stone-300'
                                        "
                                        :style="{ width: row.barWidth }"
                                    ></div>
                                </div>
                                <p class="mt-1.5 text-xs text-stone-500">
                                    {{ row.note }}
                                </p>
                            </div>
                        </div>

                        <p
                            class="mt-6 border-t border-stone-100 pt-4 text-xs leading-relaxed text-stone-400"
                        >
                            Comparison is illustrative; third-party pricing
                            varies by plan and region. Stripe's standard
                            processing fees apply on every platform.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section
            id="features"
            class="border-t border-stone-900/5 bg-white py-20 sm:py-24"
        >
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-2xl">
                    <h2
                        class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl"
                    >
                        Everything you need to take orders online
                    </h2>
                    <p class="mt-3 text-stone-600">
                        One admin for your storefront, menu, payments, and
                        loyalty — no plugins to glue together.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-2xl bg-cream/70 p-7 ring-1 ring-stone-900/5 transition hover:ring-stone-900/10"
                    >
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-700/10 text-teal-700"
                        >
                            <component :is="feature.icon" class="size-5" />
                        </div>
                        <h3 class="mt-5 text-base font-semibold text-stone-900">
                            {{ feature.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-stone-600">
                            {{ feature.description }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pricing -->
        <section id="pricing" class="py-20 sm:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="grid items-center gap-12 lg:grid-cols-[1fr_1.2fr]">
                    <div>
                        <h2
                            class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl"
                        >
                            Simple, honest pricing
                        </h2>
                        <p class="mt-4 leading-relaxed text-stone-600">
                            We charge 4% of the food subtotal — not tax, not
                            tips, not delivery fees. No monthly fee, no tiers,
                            no minimums. Just a simple cut when you make a sale.
                        </p>
                        <div class="mt-8 flex items-baseline gap-3">
                            <span
                                class="text-7xl font-bold tracking-tight text-teal-700"
                                >4%</span
                            >
                            <span class="text-lg text-stone-500"
                                >per order. That's it.</span
                            >
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div
                            class="rounded-2xl bg-white p-6 text-center ring-1 ring-stone-900/5"
                        >
                            <p
                                class="text-3xl font-bold tracking-tight text-stone-900"
                            >
                                $0
                            </p>
                            <p class="mt-1 text-sm text-stone-500">
                                monthly fee
                            </p>
                        </div>
                        <div
                            class="rounded-2xl bg-white p-6 text-center ring-1 ring-stone-900/5"
                        >
                            <p
                                class="text-3xl font-bold tracking-tight text-stone-900"
                            >
                                $0
                            </p>
                            <p class="mt-1 text-sm text-stone-500">
                                setup cost
                            </p>
                        </div>
                        <div
                            class="rounded-2xl bg-white p-6 text-center ring-1 ring-stone-900/5"
                        >
                            <p
                                class="text-3xl font-bold tracking-tight text-stone-900"
                            >
                                0
                            </p>
                            <p class="mt-1 text-sm text-stone-500">
                                contracts or minimums
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section
            id="how-it-works"
            class="border-t border-stone-900/5 bg-white py-20 sm:py-24"
        >
            <div class="mx-auto max-w-6xl px-6">
                <h2
                    class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl"
                >
                    How it works
                </h2>
                <div class="mt-14 grid gap-10 sm:grid-cols-3">
                    <div v-for="(step, index) in steps" :key="step.title">
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-teal-700 text-sm font-bold text-white shadow-md shadow-teal-900/20"
                        >
                            {{ index + 1 }}
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-stone-900">
                            {{ step.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-stone-600">
                            {{ step.description }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Final CTA -->
        <section class="py-20 sm:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div
                    class="relative overflow-hidden rounded-3xl bg-teal-900 px-8 py-16 text-center sm:px-16"
                >
                    <div
                        aria-hidden="true"
                        class="pointer-events-none absolute inset-0"
                    >
                        <svg
                            class="absolute -top-24 -right-16 h-72 w-72 text-white/[0.06]"
                            viewBox="0 0 200 200"
                            fill="none"
                        >
                            <circle
                                cx="100"
                                cy="100"
                                r="96"
                                stroke="currentColor"
                                stroke-width="7"
                            />
                            <circle
                                cx="100"
                                cy="100"
                                r="70"
                                stroke="currentColor"
                                stroke-width="5"
                            />
                        </svg>
                        <div
                            class="absolute -bottom-20 -left-10 h-64 w-64 rounded-full bg-teal-400/10 blur-2xl"
                        ></div>
                    </div>

                    <div
                        class="relative mx-auto flex max-w-xl flex-col items-center"
                    >
                        <h2
                            class="text-3xl font-bold tracking-tight text-white sm:text-4xl"
                        >
                            Ready to put your restaurant on Plateful?
                        </h2>
                        <p class="mt-4 text-teal-100/80">
                            No card required to sign up. Start free, pay only
                            when you make money.
                        </p>
                        <a
                            v-if="hasAdminAccess"
                            :href="adminUrl"
                            class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3 text-sm font-semibold text-teal-900 shadow-lg transition hover:bg-teal-50"
                        >
                            Go to your dashboard
                            <ArrowRight class="size-4" />
                        </a>
                        <Link
                            v-else
                            :href="createSignup()"
                            class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3 text-sm font-semibold text-teal-900 shadow-lg transition hover:bg-teal-50"
                        >
                            Get started
                            <ArrowRight class="size-4" />
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    </MarketingLayout>
</template>
