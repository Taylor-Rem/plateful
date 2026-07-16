<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Search,
    Sparkles,
    UserRound,
    Store,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { landing as forRestaurantsLanding } from '@/actions/App/Http/Controllers/OwnerSignupController';
import MarketingLayout from '@/layouts/MarketingLayout.vue';

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

    if (!q) {
        return props.restaurants;
    }

    return props.restaurants.filter((r) => {
        return (
            r.name.toLowerCase().includes(q) ||
            r.city.toLowerCase().includes(q) ||
            r.state.toLowerCase().includes(q) ||
            (r.description ?? '').toLowerCase().includes(q)
        );
    });
});

const loyaltySteps = [
    {
        title: 'Order from any restaurant',
        description:
            'Sign up once at any Plateful restaurant. The same account works everywhere on the platform.',
    },
    {
        title: 'Earn points automatically',
        description:
            'Every completed order earns loyalty points with that restaurant. No app, no card, no friction.',
    },
    {
        title: 'Rewards that stay local',
        description:
            "Points are kept per restaurant — your local cafe's rewards stay with your local cafe.",
    },
];
</script>

<template>
    <Head title="Plateful — Order from local restaurants" />

    <MarketingLayout :admin-url="adminUrl">
        <template #nav>
            <a
                href="#restaurants"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
            >
                Restaurants
            </a>
            <a
                href="#how-loyalty-works"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
            >
                How loyalty works
            </a>
            <Link
                :href="forRestaurantsLanding()"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                data-test="for-restaurants-link"
            >
                For restaurants
            </Link>
        </template>

        <template #actions>
            <a
                v-if="hasAdminAccess"
                :href="adminUrl"
                class="ml-2 inline-flex items-center gap-1.5 rounded-full bg-teal-700 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-teal-800"
                data-test="nav-admin-console"
            >
                Admin console
                <ArrowRight class="size-3.5" />
            </a>
            <a
                v-else-if="!authUserName"
                href="/login"
                class="ml-2 inline-flex items-center rounded-full border border-stone-900/10 bg-white px-4 py-2 font-medium text-stone-700 shadow-sm transition hover:border-stone-900/20 hover:text-stone-900"
                data-test="nav-sign-in"
            >
                Sign in
            </a>
        </template>

        <!-- Hero -->
        <section class="relative overflow-hidden">
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0"
            >
                <div
                    class="absolute -top-32 -right-24 h-96 w-96 rounded-full bg-teal-100/70 blur-3xl"
                ></div>
                <div
                    class="absolute top-40 -left-32 h-80 w-80 rounded-full bg-crimson-100/50 blur-3xl"
                ></div>
                <!-- Plate motif echoing the logo -->
                <svg
                    class="absolute top-16 right-[-6rem] hidden h-[26rem] w-[26rem] text-teal-600/[0.07] lg:block"
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
                    <circle cx="100" cy="100" r="42" fill="currentColor" />
                </svg>
            </div>

            <div
                class="relative mx-auto max-w-6xl px-6 pt-20 pb-24 sm:pt-28 sm:pb-32"
            >
                <div class="max-w-2xl">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-crimson-600/15 bg-crimson-50 px-3.5 py-1.5 text-xs font-semibold tracking-wide text-crimson-700"
                    >
                        <Sparkles class="size-3.5" />
                        Order direct · No middlemen
                    </span>

                    <h1
                        class="mt-6 text-5xl leading-[1.05] font-bold tracking-tight text-stone-900 sm:text-6xl"
                    >
                        Find the restaurants
                        <span class="text-teal-700">you love.</span>
                    </h1>

                    <p
                        class="mt-6 max-w-xl text-lg leading-relaxed text-stone-600"
                    >
                        Plateful is direct online ordering for independent
                        restaurants. Order from any restaurant on Plateful with
                        one account — and earn loyalty points everywhere you go.
                    </p>

                    <form @submit.prevent class="mt-10 max-w-lg">
                        <label class="sr-only" for="restaurant-search"
                            >Search restaurants</label
                        >
                        <div class="relative">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-5 size-5 -translate-y-1/2 text-stone-400"
                            />
                            <input
                                id="restaurant-search"
                                v-model="query"
                                type="search"
                                placeholder="Search by name, city, or cuisine"
                                class="w-full rounded-full border border-stone-900/10 bg-white py-4 pr-6 pl-13 text-base text-stone-900 shadow-lg shadow-stone-900/5 transition outline-none placeholder:text-stone-400 focus:border-teal-600/40 focus:ring-4 focus:ring-teal-600/10"
                                data-test="restaurant-search-input"
                            />
                        </div>
                    </form>

                    <p
                        v-if="authUserName"
                        class="mt-5 inline-flex items-center gap-2 rounded-full bg-teal-50 px-4 py-2 text-sm font-medium text-teal-800"
                        data-test="auth-greeting"
                    >
                        <UserRound class="size-4" />
                        Welcome back, {{ authUserName }}.
                    </p>

                    <div
                        class="mt-10 flex flex-wrap items-center gap-x-8 gap-y-3 text-sm font-medium text-stone-500"
                    >
                        <span class="inline-flex items-center gap-2">
                            <span
                                class="size-1.5 rounded-full bg-teal-600"
                            ></span>
                            One account, every restaurant
                        </span>
                        <span class="inline-flex items-center gap-2">
                            <span
                                class="size-1.5 rounded-full bg-teal-600"
                            ></span>
                            Loyalty points on every order
                        </span>
                        <span class="inline-flex items-center gap-2">
                            <span
                                class="size-1.5 rounded-full bg-teal-600"
                            ></span>
                            Your money goes to the restaurant
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Restaurants -->
        <section
            id="restaurants"
            class="border-t border-stone-900/5 bg-white py-20 sm:py-24"
        >
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-2xl">
                    <h2
                        class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl"
                    >
                        Restaurants on Plateful
                    </h2>
                    <p class="mt-3 text-stone-600">
                        {{ filteredRestaurants.length }}
                        {{
                            filteredRestaurants.length === 1
                                ? 'place'
                                : 'places'
                        }}
                        to order from
                    </p>
                </div>

                <div
                    v-if="filteredRestaurants.length === 0"
                    class="mt-12 rounded-2xl border border-dashed border-stone-300 bg-cream/60 p-12 text-center text-sm text-stone-500"
                    data-test="no-restaurants"
                >
                    <template v-if="restaurants.length === 0">
                        No restaurants on Plateful just yet. Are you a
                        restaurant owner?
                        <Link
                            :href="forRestaurantsLanding()"
                            class="font-semibold text-teal-700 underline-offset-4 hover:underline"
                        >
                            Get started </Link
                        >.
                    </template>
                    <template v-else> No matches for "{{ query }}". </template>
                </div>

                <div
                    v-else
                    class="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3"
                    data-test="restaurant-grid"
                >
                    <a
                        v-for="restaurant in filteredRestaurants"
                        :key="restaurant.url"
                        :href="restaurant.url"
                        class="group flex flex-col rounded-2xl bg-white p-6 ring-1 ring-stone-900/5 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-stone-900/5 hover:ring-stone-900/10"
                    >
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-teal-500 to-teal-700 ring-2 ring-white"
                            >
                                <img
                                    v-if="restaurant.logoUrl"
                                    :src="restaurant.logoUrl"
                                    :alt="`${restaurant.name} logo`"
                                    class="h-full w-full object-cover"
                                />
                                <span
                                    v-else
                                    class="text-xl font-bold text-white"
                                >
                                    {{ restaurant.name.charAt(0) }}
                                </span>
                            </div>
                            <div class="min-w-0">
                                <h3
                                    class="truncate text-base font-semibold text-stone-900 group-hover:text-teal-700"
                                >
                                    {{ restaurant.name }}
                                </h3>
                                <p class="text-sm text-stone-500">
                                    {{ restaurant.city
                                    }}<span v-if="restaurant.state"
                                        >, {{ restaurant.state }}</span
                                    >
                                </p>
                            </div>
                        </div>
                        <p
                            v-if="restaurant.description"
                            class="mt-4 line-clamp-3 text-sm leading-relaxed text-stone-600"
                        >
                            {{ restaurant.description }}
                        </p>
                        <span
                            class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-teal-700"
                        >
                            Order now
                            <ArrowRight
                                class="size-4 transition-transform group-hover:translate-x-0.5"
                            />
                        </span>
                    </a>
                </div>
            </div>
        </section>

        <!-- Loyalty -->
        <section id="how-loyalty-works" class="py-20 sm:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-2xl">
                    <h2
                        class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl"
                    >
                        How loyalty works on Plateful
                    </h2>
                    <p class="mt-3 text-stone-600">
                        One rewards profile that follows you to every
                        independent restaurant on the platform.
                    </p>
                </div>

                <div class="mt-14 grid grid-cols-1 gap-10 md:grid-cols-3">
                    <div
                        v-for="(step, index) in loyaltySteps"
                        :key="step.title"
                        class="relative"
                    >
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

        <!-- Owner CTA -->
        <section class="pb-20 sm:pb-24">
            <div class="mx-auto max-w-6xl px-6">
                <div
                    class="relative overflow-hidden rounded-3xl bg-teal-900 px-8 py-16 text-center sm:px-16"
                >
                    <div
                        aria-hidden="true"
                        class="pointer-events-none absolute inset-0"
                    >
                        <svg
                            class="absolute -bottom-24 -left-16 h-72 w-72 text-white/[0.06]"
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
                            class="absolute -top-20 -right-10 h-64 w-64 rounded-full bg-teal-400/10 blur-2xl"
                        ></div>
                    </div>

                    <div
                        class="relative mx-auto flex max-w-xl flex-col items-center"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-semibold tracking-wide text-teal-100"
                        >
                            <Store class="size-3.5" />
                            For restaurant owners
                        </span>
                        <h2
                            class="mt-5 text-3xl font-bold tracking-tight text-white sm:text-4xl"
                        >
                            Own a restaurant?
                        </h2>
                        <p class="mt-4 text-teal-100/80">
                            Get your own branded ordering site, take orders
                            direct, and keep loyalty in-house. Sign up in a
                            couple of minutes.
                        </p>
                        <Link
                            :href="forRestaurantsLanding()"
                            class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3 text-sm font-semibold text-teal-900 shadow-lg transition hover:bg-teal-50"
                            data-test="footer-for-restaurants-cta"
                        >
                            Learn more
                            <ArrowRight class="size-4" />
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    </MarketingLayout>
</template>
