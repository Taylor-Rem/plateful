<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { landing as forRestaurantsLanding } from '@/actions/App/Http/Controllers/OwnerSignupController';

type RestaurantSummary = {
    name: string;
    description: string | null;
    city: string;
    state: string;
    logoUrl: string | null;
    url: string;
};

const props = defineProps<{
    adminUrl: string;
    restaurants: RestaurantSummary[];
    authUserName: string | null;
    hasAdminAccess: boolean;
}>();

const query = ref('');

const filteredRestaurants = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.restaurants;
    return props.restaurants.filter((r) => {
        return (
            r.name.toLowerCase().includes(q) ||
            r.city.toLowerCase().includes(q) ||
            r.state.toLowerCase().includes(q) ||
            (r.description ?? '').toLowerCase().includes(q)
        );
    });
});
</script>

<template>
    <Head title="Plateful — Order from local restaurants" />

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
                    <span class="text-lg font-semibold tracking-tight"
                        >Plateful</span
                    >
                </Link>

                <nav class="flex items-center gap-2 text-sm">
                    <a
                        href="#restaurants"
                        class="hidden px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] sm:inline-block dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]"
                    >
                        Restaurants
                    </a>
                    <a
                        href="#how-loyalty-works"
                        class="hidden px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] sm:inline-block dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]"
                    >
                        How loyalty works
                    </a>
                    <Link
                        :href="forRestaurantsLanding()"
                        class="hidden rounded-md border border-[#19140035] px-3 py-1.5 hover:bg-black/5 sm:inline-block dark:border-[#3E3E3A] dark:hover:bg-white/5"
                        data-test="for-restaurants-link"
                    >
                        For restaurants
                    </Link>
                    <a
                        v-if="hasAdminAccess"
                        :href="adminUrl"
                        class="rounded-md bg-[#f53003] px-3 py-1.5 text-white hover:bg-[#d62a02] dark:bg-[#FF4433] dark:hover:bg-[#e63b2c]"
                        data-test="nav-admin-console"
                    >
                        Admin console →
                    </a>
                    <a
                        v-else-if="!authUserName"
                        href="/login"
                        class="px-3 py-1.5 text-[#1b1b18]/70 hover:text-[#1b1b18] dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]"
                        data-test="nav-sign-in"
                    >
                        Sign in
                    </a>
                </nav>
            </div>
        </header>

        <main>
            <section class="mx-auto max-w-6xl px-6 py-20 sm:py-24">
                <div class="grid items-center gap-12 lg:grid-cols-[1.2fr_1fr]">
                    <div>
                        <span
                            class="inline-flex items-center rounded-full border border-[#f53003]/20 bg-[#fff2f2] px-3 py-1 text-xs font-medium text-[#f53003] dark:border-[#FF4433]/30 dark:bg-[#1D0002] dark:text-[#FF4433]"
                        >
                            Order direct. No middlemen.
                        </span>
                        <h1
                            class="mt-5 text-4xl leading-tight font-semibold tracking-tight sm:text-5xl"
                        >
                            Find the restaurants
                            <span class="text-[#f53003] dark:text-[#FF4433]"
                                >you love.</span
                            >
                        </h1>
                        <p
                            class="mt-5 max-w-xl text-lg text-[#1b1b18]/70 dark:text-[#EDEDEC]/70"
                        >
                            Plateful is direct online ordering for independent
                            restaurants. Order from any restaurant on Plateful
                            with one account — and earn loyalty points
                            everywhere you go.
                        </p>

                        <form
                            @submit.prevent
                            class="mt-8 flex max-w-lg items-center gap-2"
                        >
                            <label class="sr-only" for="restaurant-search"
                                >Search restaurants</label
                            >
                            <input
                                id="restaurant-search"
                                v-model="query"
                                type="search"
                                placeholder="Search by name, city, or cuisine"
                                class="w-full rounded-md border border-[#19140035] bg-white px-4 py-2.5 text-sm placeholder:text-[#1b1b18]/40 dark:border-[#3E3E3A] dark:bg-white/5 dark:placeholder:text-[#EDEDEC]/40"
                                data-test="restaurant-search-input"
                            />
                        </form>
                    </div>

                    <div
                        class="rounded-2xl border border-black/5 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/5"
                    >
                        <p
                            class="text-xs font-medium tracking-wide text-[#1b1b18]/60 uppercase dark:text-[#EDEDEC]/60"
                        >
                            One account, every restaurant
                        </p>
                        <p class="mt-3 text-sm">
                            Sign up at any Plateful restaurant and your account
                            works at all of them. Saved addresses, payment
                            methods, and a single rewards profile across the
                            platform.
                        </p>

                        <p
                            v-if="authUserName"
                            class="mt-4 rounded-md bg-[#fff2f2] px-3 py-2 text-xs text-[#1b1b18] dark:bg-[#1D0002] dark:text-[#EDEDEC]"
                            data-test="auth-greeting"
                        >
                            Welcome back, {{ authUserName }}.
                        </p>
                    </div>
                </div>
            </section>

            <section
                id="restaurants"
                class="border-t border-black/5 bg-white py-20 dark:border-white/10 dark:bg-[#0f0f0e]"
            >
                <div class="mx-auto max-w-6xl px-6">
                    <div class="flex items-end justify-between">
                        <div>
                            <h2 class="text-3xl font-semibold tracking-tight">
                                Restaurants on Plateful
                            </h2>
                            <p
                                class="mt-2 text-[#1b1b18]/70 dark:text-[#EDEDEC]/70"
                            >
                                {{ filteredRestaurants.length }}
                                {{
                                    filteredRestaurants.length === 1
                                        ? 'place'
                                        : 'places'
                                }}
                                to order from
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="filteredRestaurants.length === 0"
                        class="mt-10 rounded-lg border border-dashed border-black/10 bg-white p-10 text-center text-sm text-[#1b1b18]/60 dark:border-white/10 dark:bg-white/5 dark:text-[#EDEDEC]/60"
                        data-test="no-restaurants"
                    >
                        <template v-if="restaurants.length === 0">
                            No restaurants on Plateful just yet. Are you a
                            restaurant owner?
                            <Link
                                :href="forRestaurantsLanding()"
                                class="font-medium text-[#f53003] underline-offset-4 hover:underline dark:text-[#FF4433]"
                            >
                                Get started </Link
                            >.
                        </template>
                        <template v-else>
                            No matches for "{{ query }}".
                        </template>
                    </div>

                    <div
                        v-else
                        class="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3"
                        data-test="restaurant-grid"
                    >
                        <a
                            v-for="restaurant in filteredRestaurants"
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
                                        class="text-sm text-[#1b1b18]/60 dark:text-[#EDEDEC]/60"
                                    >
                                        {{ restaurant.city
                                        }}<span v-if="restaurant.state"
                                            >, {{ restaurant.state }}</span
                                        >
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="restaurant.description"
                                class="mt-4 line-clamp-3 text-sm text-[#1b1b18]/70 dark:text-[#EDEDEC]/70"
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
                id="how-loyalty-works"
                class="border-t border-black/5 py-20 dark:border-white/10"
            >
                <div class="mx-auto max-w-6xl px-6">
                    <h2 class="text-3xl font-semibold tracking-tight">
                        How loyalty works on Plateful
                    </h2>
                    <div class="mt-10 grid grid-cols-1 gap-6 md:grid-cols-3">
                        <div
                            class="rounded-lg border border-black/5 bg-white p-6 dark:border-white/10 dark:bg-[#161615]"
                        >
                            <span
                                class="font-mono text-sm text-[#f53003] dark:text-[#FF4433]"
                                >01</span
                            >
                            <h3 class="mt-3 text-lg font-semibold">
                                Order from any restaurant
                            </h3>
                            <p
                                class="mt-2 text-sm text-[#1b1b18]/70 dark:text-[#EDEDEC]/70"
                            >
                                Sign up once at any Plateful restaurant. The
                                same account works everywhere on the platform.
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-black/5 bg-white p-6 dark:border-white/10 dark:bg-[#161615]"
                        >
                            <span
                                class="font-mono text-sm text-[#f53003] dark:text-[#FF4433]"
                                >02</span
                            >
                            <h3 class="mt-3 text-lg font-semibold">
                                Earn points automatically
                            </h3>
                            <p
                                class="mt-2 text-sm text-[#1b1b18]/70 dark:text-[#EDEDEC]/70"
                            >
                                Every completed order earns loyalty points with
                                that restaurant. No app, no card, no friction.
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-black/5 bg-white p-6 dark:border-white/10 dark:bg-[#161615]"
                        >
                            <span
                                class="font-mono text-sm text-[#f53003] dark:text-[#FF4433]"
                                >03</span
                            >
                            <h3 class="mt-3 text-lg font-semibold">
                                Redeem at the place you earned it
                            </h3>
                            <p
                                class="mt-2 text-sm text-[#1b1b18]/70 dark:text-[#EDEDEC]/70"
                            >
                                Points are kept per restaurant — your local
                                cafe's rewards stay with your local cafe.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="border-t border-black/5 bg-[#1b1b18] py-16 text-white dark:border-white/10 dark:bg-[#161615]"
            >
                <div
                    class="mx-auto flex max-w-4xl flex-col items-center px-6 text-center"
                >
                    <h2
                        class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        Own a restaurant?
                    </h2>
                    <p class="mt-3 max-w-xl text-white/70">
                        Get your own branded ordering site, take orders direct,
                        and keep loyalty in-house. Sign up in a couple of
                        minutes.
                    </p>
                    <Link
                        :href="forRestaurantsLanding()"
                        class="mt-6 inline-flex items-center rounded-md bg-[#f53003] px-6 py-3 text-sm font-medium text-white hover:bg-[#d92900]"
                        data-test="footer-for-restaurants-cta"
                    >
                        Learn more →
                    </Link>
                </div>
            </section>
        </main>

        <footer
            class="border-t border-black/5 py-10 text-sm text-[#1b1b18]/60 dark:border-white/10 dark:text-[#EDEDEC]/60"
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
                <div class="flex flex-wrap items-center justify-center gap-5">
                    <a
                        href="#restaurants"
                        class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                        >Restaurants</a
                    >
                    <Link
                        :href="forRestaurantsLanding()"
                        class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                        >For restaurants</Link
                    >
                    <Link
                        href="/terms"
                        class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                        >Terms</Link
                    >
                    <Link
                        href="/privacy"
                        class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                        >Privacy</Link
                    >
                    <a
                        :href="adminUrl"
                        class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                        >Admin</a
                    >
                </div>
            </div>
        </footer>
    </div>
</template>
