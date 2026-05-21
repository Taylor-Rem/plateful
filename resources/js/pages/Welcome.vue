<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

type RestaurantSummary = {
    name: string;
    description: string | null;
    city: string;
    state: string;
    logoUrl: string | null;
    url: string;
};

defineProps<{
    adminUrl: string;
    restaurants: RestaurantSummary[];
}>();

const features = [
    {
        title: 'Branded online ordering',
        description:
            'Give every restaurant its own storefront with menus, cart, checkout, and order tracking — on your subdomain or a custom domain.',
    },
    {
        title: 'Menus that stay in sync',
        description:
            'Categories, items, modifiers, and templates managed from one admin. Updates ship instantly to every channel.',
    },
    {
        title: 'Hours and availability',
        description:
            'Per-location hours with closed-state enforcement so customers never place an order you can’t fulfil.',
    },
    {
        title: 'Loyalty built in',
        description:
            'Customers earn points on every completed order — no third-party integration, no extra fees.',
    },
    {
        title: 'Multi-staff admin',
        description:
            'Invite managers and staff per restaurant with the right scope. Super admins manage the whole platform.',
    },
    {
        title: 'Stripe payments',
        description:
            'Backed by Stripe with subscription billing for restaurants and secure checkout for diners.',
    },
];

const steps = [
    {
        number: '01',
        title: 'Tell us about your restaurant',
        description:
            'Pick a subdomain, set your timezone, and we’ll spin up your storefront.',
    },
    {
        number: '02',
        title: 'Build your menu',
        description:
            'Add categories and items in minutes. Reuse templates across locations.',
    },
    {
        number: '03',
        title: 'Start taking orders',
        description:
            'Share your link, take orders, and watch loyalty points add up automatically.',
    },
];
</script>

<template>
    <Head title="Plateful — Online ordering for modern restaurants">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <div
        class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
    >
        <header class="border-b border-black/5 dark:border-white/10">
            <div
                class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5"
            >
                <Link href="/" class="flex items-center gap-2">
                    <AppLogoIcon
                        class-name="h-7 w-7 text-[#f53003] dark:text-[#FF4433]"
                    />
                    <span class="text-lg font-semibold tracking-tight">
                        Plateful
                    </span>
                </Link>

                <nav class="flex items-center gap-2 text-sm">
                    <a
                        href="#features"
                        class="hidden px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] sm:inline-block dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]"
                    >
                        Features
                    </a>
                    <a
                        href="#how-it-works"
                        class="hidden px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] sm:inline-block dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]"
                    >
                        How it works
                    </a>
                    <a
                        v-if="restaurants.length"
                        href="#restaurants"
                        class="hidden px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] sm:inline-block dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]"
                    >
                        Restaurants
                    </a>
                    <a
                        :href="adminUrl"
                        class="rounded-sm border border-transparent px-4 py-1.5 hover:border-[#19140035] dark:hover:border-[#3E3E3A]"
                    >
                        Admin console
                    </a>
                    <a
                        href="#get-started"
                        class="rounded-sm border border-black bg-[#1b1b18] px-4 py-1.5 text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    >
                        Get started
                    </a>
                </nav>
            </div>
        </header>

        <main>
            <section class="relative overflow-hidden">
                <div
                    class="mx-auto max-w-6xl px-6 py-20 lg:grid lg:grid-cols-2 lg:items-center lg:gap-16 lg:py-28"
                >
                    <div>
                        <span
                            class="inline-flex items-center rounded-full border border-[#f53003]/20 bg-[#fff2f2] px-3 py-1 text-xs font-medium text-[#f53003] dark:border-[#FF4433]/30 dark:bg-[#1D0002] dark:text-[#FF4433]"
                        >
                            Online ordering, reimagined
                        </span>
                        <h1
                            class="mt-5 text-4xl font-semibold tracking-tight sm:text-5xl lg:text-6xl"
                        >
                            Run your restaurant.
                            <span class="text-[#f53003] dark:text-[#FF4433]">
                                We’ll handle the ordering.
                            </span>
                        </h1>
                        <p
                            class="mt-6 max-w-xl text-lg text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Plateful is a hosted online ordering platform built
                            for independent restaurants. Branded storefront,
                            menu management, loyalty, and Stripe payments —
                            without per-order fees from a marketplace.
                        </p>
                        <div class="mt-8 flex flex-wrap items-center gap-3">
                            <a
                                href="#get-started"
                                class="inline-flex items-center rounded-sm border border-black bg-[#1b1b18] px-6 py-3 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                            >
                                Get started
                            </a>
                            <a
                                href="#features"
                                class="inline-flex items-center rounded-sm border border-[#19140035] px-6 py-3 text-sm font-medium hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]"
                            >
                                See what’s included
                            </a>
                        </div>
                    </div>

                    <div class="relative mt-16 lg:mt-0">
                        <div
                            class="aspect-[4/3] w-full rounded-2xl bg-gradient-to-br from-[#fff2f2] via-white to-[#fde6d3] p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.08)] dark:from-[#1D0002] dark:via-[#161615] dark:to-[#1f0f00] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]"
                        >
                            <div
                                class="flex h-full flex-col rounded-xl bg-white p-5 shadow-sm dark:bg-[#161615]"
                            >
                                <div
                                    class="flex items-center justify-between border-b border-black/5 pb-3 dark:border-white/10"
                                >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="h-2.5 w-2.5 rounded-full bg-[#f53003]"
                                        />
                                        <span class="text-sm font-medium">
                                            northside-cafe.plateful.app
                                        </span>
                                    </div>
                                    <span
                                        class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                                    >
                                        Open
                                    </span>
                                </div>
                                <div class="mt-4 grid flex-1 grid-cols-2 gap-3">
                                    <div
                                        v-for="item in [
                                            {
                                                name: 'Pancake Stack',
                                                price: '$12',
                                                tag: 'Breakfast',
                                            },
                                            {
                                                name: 'Breakfast Burrito',
                                                price: '$11',
                                                tag: 'Breakfast',
                                            },
                                            {
                                                name: 'Iced Latte',
                                                price: '$5',
                                                tag: 'Drinks',
                                            },
                                            {
                                                name: 'Avocado Toast',
                                                price: '$10',
                                                tag: 'Brunch',
                                            },
                                        ]"
                                        :key="item.name"
                                        class="flex flex-col justify-between rounded-lg border border-black/5 p-3 text-sm dark:border-white/10"
                                    >
                                        <div>
                                            <p class="font-medium">
                                                {{ item.name }}
                                            </p>
                                            <p
                                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                            >
                                                {{ item.tag }}
                                            </p>
                                        </div>
                                        <div class="mt-3 flex items-center justify-between">
                                            <span class="text-sm font-semibold">
                                                {{ item.price }}
                                            </span>
                                            <span
                                                class="rounded-sm bg-[#1b1b18] px-2 py-0.5 text-xs text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                                            >
                                                Add
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="features"
                class="border-t border-black/5 bg-white py-20 dark:border-white/10 dark:bg-[#0f0f0e]"
            >
                <div class="mx-auto max-w-6xl px-6">
                    <div class="max-w-2xl">
                        <h2
                            class="text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            Everything you need to take orders online
                        </h2>
                        <p
                            class="mt-4 text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            No plugins, no patchwork. Plateful gives you a
                            storefront, admin, and payments — all in one place.
                        </p>
                    </div>
                    <div
                        class="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div
                            v-for="feature in features"
                            :key="feature.title"
                            class="rounded-lg border border-black/5 p-6 dark:border-white/10"
                        >
                            <h3 class="text-base font-semibold">
                                {{ feature.title }}
                            </h3>
                            <p
                                class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ feature.description }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="how-it-works"
                class="border-t border-black/5 py-20 dark:border-white/10"
            >
                <div class="mx-auto max-w-6xl px-6">
                    <div class="max-w-2xl">
                        <h2
                            class="text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            Live in three steps
                        </h2>
                        <p
                            class="mt-4 text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Most restaurants are taking orders the same day.
                        </p>
                    </div>
                    <div
                        class="mt-12 grid grid-cols-1 gap-6 md:grid-cols-3"
                    >
                        <div
                            v-for="step in steps"
                            :key="step.number"
                            class="rounded-lg border border-black/5 bg-white p-6 dark:border-white/10 dark:bg-[#161615]"
                        >
                            <span
                                class="text-sm font-semibold text-[#f53003] dark:text-[#FF4433]"
                            >
                                {{ step.number }}
                            </span>
                            <h3 class="mt-3 text-lg font-semibold">
                                {{ step.title }}
                            </h3>
                            <p
                                class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ step.description }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-if="restaurants.length"
                id="restaurants"
                class="border-t border-black/5 bg-white py-20 dark:border-white/10 dark:bg-[#0f0f0e]"
            >
                <div class="mx-auto max-w-6xl px-6">
                    <div class="max-w-2xl">
                        <h2
                            class="text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            Restaurants on Plateful
                        </h2>
                        <p class="mt-4 text-[#706f6c] dark:text-[#A1A09A]">
                            Browse the restaurants taking orders through
                            Plateful and head straight to their storefront.
                        </p>
                    </div>
                    <div
                        class="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <a
                            v-for="restaurant in restaurants"
                            :key="restaurant.url"
                            :href="restaurant.url"
                            class="group flex flex-col rounded-lg border border-black/5 bg-white p-6 transition hover:border-black/20 hover:shadow-sm dark:border-white/10 dark:bg-[#161615] dark:hover:border-white/30"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#fff2f2] dark:bg-[#1D0002]"
                                >
                                    <img
                                        v-if="restaurant.logoUrl"
                                        :src="restaurant.logoUrl"
                                        :alt="`${restaurant.name} logo`"
                                        class="h-full w-full object-cover"
                                    />
                                    <span
                                        v-else
                                        class="text-lg font-semibold text-[#f53003] dark:text-[#FF4433]"
                                    >
                                        {{ restaurant.name.charAt(0) }}
                                    </span>
                                </div>
                                <div class="min-w-0">
                                    <h3
                                        class="truncate text-base font-semibold group-hover:text-[#f53003] dark:group-hover:text-[#FF4433]"
                                    >
                                        {{ restaurant.name }}
                                    </h3>
                                    <p
                                        class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        {{ restaurant.city }}, {{ restaurant.state }}
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="restaurant.description"
                                class="mt-4 line-clamp-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ restaurant.description }}
                            </p>
                            <span
                                class="mt-4 inline-flex items-center text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
                            >
                                Order now →
                            </span>
                        </a>
                    </div>
                </div>
            </section>

            <section
                id="get-started"
                class="border-t border-black/5 bg-[#1b1b18] py-20 text-white dark:border-white/10 dark:bg-[#161615]"
            >
                <div
                    class="mx-auto flex max-w-4xl flex-col items-center px-6 text-center"
                >
                    <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                        Ready to bring your restaurant online?
                    </h2>
                    <p class="mt-4 max-w-xl text-white/70">
                        Tell us a little about your restaurant and we’ll get
                        your storefront set up.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                        <a
                            href="mailto:hello@plateful.app?subject=I%27d%20like%20to%20get%20started%20with%20Plateful"
                            class="inline-flex items-center rounded-sm bg-[#f53003] px-6 py-3 text-sm font-medium text-white hover:bg-[#d92900]"
                        >
                            Request access
                        </a>
                        <a
                            :href="adminUrl"
                            class="inline-flex items-center rounded-sm border border-white/30 px-6 py-3 text-sm font-medium text-white hover:border-white/60"
                        >
                            Go to admin console
                        </a>
                    </div>
                </div>
            </section>
        </main>

        <footer
            class="border-t border-black/5 py-10 text-sm text-[#706f6c] dark:border-white/10 dark:text-[#A1A09A]"
        >
            <div
                class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-6 sm:flex-row"
            >
                <div class="flex items-center gap-2">
                    <AppLogoIcon
                        class-name="h-5 w-5 text-[#f53003] dark:text-[#FF4433]"
                    />
                    <span>© {{ new Date().getFullYear() }} Plateful</span>
                </div>
                <div class="flex items-center gap-5">
                    <a href="#features" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">
                        Features
                    </a>
                    <a href="#how-it-works" class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]">
                        How it works
                    </a>
                    <a
                        href="mailto:hello@plateful.app"
                        class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                    >
                        Contact
                    </a>
                </div>
            </div>
        </footer>
    </div>
</template>
