<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import {
    create as createSignup,
    landing as forRestaurantsLanding,
} from '@/actions/App/Http/Controllers/OwnerSignupController';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { booking } from '@/routes';

const props = defineProps<{
    authUserName: string | null;
    hasAdminAccess: boolean;
    adminUrl: string;
    feePercent: number;
    feeCapCents: number;
    stripeVariableRate: number;
    stripeFixedFeeCents: number;
    bookingUrl: string | null;
}>();

const signInUrl = computed(() => `${props.adminUrl}/login`);

/*
 * Inputs. Defaults describe a typical independent doing steady delivery-app
 * volume — believable, not inflated.
 */
const monthlySales = ref(6000);
const averageOrder = ref(35);
const commissionPercent = ref(25);

const commissionPresets = [15, 20, 25, 30];

/*
 * The honest all-in comparison. Delivery-app commissions include payment
 * processing, so the Plateful side must carry Stripe's processing cost too:
 * fee % + Stripe's variable rate on every dollar, plus the fixed per-charge
 * fee on every order. The platform fee is capped per calendar month; Stripe
 * processing is not (it goes to Stripe, not Plateful).
 */
const ordersPerMonth = computed(() =>
    averageOrder.value > 0 ? monthlySales.value / averageOrder.value : 0,
);

const feesTodayMonthly = computed(
    () => monthlySales.value * (commissionPercent.value / 100),
);

const feeCapDollars = computed(() => props.feeCapCents / 100);

const platefulPlatformFeeMonthly = computed(() =>
    Math.min(
        monthlySales.value * (props.feePercent / 100),
        feeCapDollars.value,
    ),
);

const capActive = computed(
    () => monthlySales.value * (props.feePercent / 100) >= feeCapDollars.value,
);

const platefulFeesMonthly = computed(
    () =>
        platefulPlatformFeeMonthly.value +
        monthlySales.value * props.stripeVariableRate +
        ordersPerMonth.value * (props.stripeFixedFeeCents / 100),
);

const platefulEffectivePercent = computed(() =>
    monthlySales.value > 0
        ? (platefulFeesMonthly.value / monthlySales.value) * 100
        : 0,
);

const savingsMonthly = computed(
    () => feesTodayMonthly.value - platefulFeesMonthly.value,
);
const savingsYearly = computed(() => savingsMonthly.value * 12);
const saves = computed(() => savingsMonthly.value > 0);

/*
 * Comparison bar widths: the bigger fee pile is always the full bar, the
 * other is proportional — same visual grammar as the landing-page graphic.
 */
const todayBarWidth = computed(() => {
    const max = Math.max(feesTodayMonthly.value, platefulFeesMonthly.value);

    return max > 0 ? `${(feesTodayMonthly.value / max) * 100}%` : '0%';
});
const platefulBarWidth = computed(() => {
    const max = Math.max(feesTodayMonthly.value, platefulFeesMonthly.value);

    return max > 0 ? `${(platefulFeesMonthly.value / max) * 100}%` : '0%';
});

const wholeDollars = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

function money(value: number): string {
    return wholeDollars.format(Math.round(value));
}
</script>

<template>
    <Head title="Delivery App Fee Savings Calculator — Plateful">
        <meta
            name="description"
            content="See what DoorDash and Uber Eats commissions really cost your restaurant each month — and what you'd keep with Plateful's flat 4% online ordering. Built for Utah's independent restaurants."
        />
    </Head>

    <MarketingLayout :admin-url="adminUrl">
        <template #nav>
            <Link
                :href="forRestaurantsLanding()"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >Why Plateful</Link
            >
            <a
                :href="forRestaurantsLanding().url + '#pricing'"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >Pricing</a
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
                <a
                    v-if="hasAdminAccess"
                    :href="adminUrl"
                    class="ml-2 inline-flex items-center gap-1.5 rounded-full bg-teal-700 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-teal-800"
                >
                    Admin console
                    <ArrowRight class="size-3.5" />
                </a>
            </template>
            <template v-else>
                <a
                    :href="signInUrl"
                    class="rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900"
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

        <!-- Header -->
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

            <div class="relative mx-auto max-w-6xl px-6 pt-16 pb-10 sm:pt-20">
                <p
                    class="text-sm font-semibold tracking-widest text-crimson-600 uppercase"
                >
                    Savings calculator
                </p>
                <h1
                    class="mt-4 max-w-2xl text-4xl leading-[1.1] font-bold tracking-tight text-stone-900 sm:text-5xl"
                >
                    What are the delivery apps
                    <span class="text-teal-700">really costing you?</span>
                </h1>
                <p class="mt-5 max-w-xl text-lg leading-relaxed text-stone-600">
                    Slide in your numbers. We'll show what you pay in
                    commissions today — and what the same orders would cost
                    through your own Plateful storefront, Stripe processing
                    included.
                </p>
            </div>
        </section>

        <!-- Calculator -->
        <section class="relative pb-20">
            <div class="mx-auto max-w-6xl px-6">
                <div class="grid gap-6 lg:grid-cols-[1.1fr_1fr]">
                    <!-- Inputs -->
                    <div
                        class="rounded-3xl bg-white p-8 shadow-xl ring-1 shadow-stone-900/5 ring-stone-900/5"
                    >
                        <div>
                            <div class="flex items-baseline justify-between">
                                <label
                                    for="monthly-sales"
                                    class="text-sm font-semibold text-stone-700"
                                    >Monthly sales through delivery apps</label
                                >
                                <span
                                    class="text-lg font-bold text-stone-900 tabular-nums"
                                    data-test="monthly-sales-value"
                                    >{{ money(monthlySales) }}</span
                                >
                            </div>
                            <input
                                id="monthly-sales"
                                v-model.number="monthlySales"
                                type="range"
                                min="500"
                                max="40000"
                                step="250"
                                class="mt-3 w-full accent-teal-700"
                            />
                            <p class="mt-1.5 text-xs text-stone-500">
                                Food sales only — before the apps take their
                                cut.
                            </p>
                        </div>

                        <div class="mt-8">
                            <div class="flex items-baseline justify-between">
                                <label
                                    for="average-order"
                                    class="text-sm font-semibold text-stone-700"
                                    >Average order size</label
                                >
                                <span
                                    class="text-lg font-bold text-stone-900 tabular-nums"
                                    >{{ money(averageOrder) }}</span
                                >
                            </div>
                            <input
                                id="average-order"
                                v-model.number="averageOrder"
                                type="range"
                                min="10"
                                max="100"
                                step="1"
                                class="mt-3 w-full accent-teal-700"
                            />
                        </div>

                        <div class="mt-8">
                            <div class="flex items-baseline justify-between">
                                <label
                                    for="commission-percent"
                                    class="text-sm font-semibold text-stone-700"
                                    >Your current commission rate</label
                                >
                                <span
                                    class="text-lg font-bold text-stone-900 tabular-nums"
                                    >{{ commissionPercent }}%</span
                                >
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button
                                    v-for="preset in commissionPresets"
                                    :key="preset"
                                    type="button"
                                    class="rounded-full px-4 py-1.5 text-sm font-semibold ring-1 transition"
                                    :class="
                                        commissionPercent === preset
                                            ? 'bg-teal-700 text-white ring-teal-700'
                                            : 'bg-white text-stone-600 ring-stone-900/10 hover:ring-stone-900/25'
                                    "
                                    @click="commissionPercent = preset"
                                >
                                    {{ preset }}%
                                </button>
                            </div>
                            <input
                                id="commission-percent"
                                v-model.number="commissionPercent"
                                type="range"
                                min="10"
                                max="40"
                                step="1"
                                class="mt-4 w-full accent-teal-700"
                            />
                            <p class="mt-1.5 text-xs text-stone-500">
                                DoorDash and Uber Eats marketplace plans run
                                15–30%. Most independents are on 25–30% for
                                delivery.
                            </p>
                        </div>
                    </div>

                    <!-- Results -->
                    <div class="flex flex-col gap-6">
                        <div
                            class="rounded-3xl bg-white p-8 shadow-xl ring-1 shadow-stone-900/5 ring-stone-900/5"
                        >
                            <p
                                class="text-xs font-semibold tracking-wider text-stone-500 uppercase"
                            >
                                Your monthly fees
                            </p>

                            <div class="mt-6 space-y-5">
                                <div>
                                    <div
                                        class="flex items-baseline justify-between text-sm"
                                    >
                                        <span
                                            class="font-semibold text-stone-700"
                                            >Delivery apps today</span
                                        >
                                        <span
                                            class="font-bold text-stone-900 tabular-nums"
                                            data-test="fees-today"
                                            >{{ money(feesTodayMonthly) }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-2 h-3 overflow-hidden rounded-full bg-stone-100"
                                    >
                                        <div
                                            class="h-full rounded-full bg-stone-300"
                                            :style="{ width: todayBarWidth }"
                                        ></div>
                                    </div>
                                    <p class="mt-1.5 text-xs text-stone-500">
                                        {{ commissionPercent }}% commission
                                    </p>
                                </div>

                                <div>
                                    <div
                                        class="flex items-baseline justify-between text-sm"
                                    >
                                        <span
                                            class="font-semibold text-teal-700"
                                            >Same orders on Plateful</span
                                        >
                                        <span
                                            class="font-bold text-teal-700 tabular-nums"
                                            data-test="fees-plateful"
                                            >{{
                                                money(platefulFeesMonthly)
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-2 h-3 overflow-hidden rounded-full bg-stone-100"
                                    >
                                        <div
                                            class="h-full rounded-full bg-teal-600"
                                            :style="{ width: platefulBarWidth }"
                                        ></div>
                                    </div>
                                    <p class="mt-1.5 text-xs text-stone-500">
                                        <template v-if="capActive">
                                            {{ feePercent }}% fee capped at
                                            {{ money(feeCapDollars) }}/mo +
                                            Stripe processing ≈
                                            {{
                                                platefulEffectivePercent.toFixed(
                                                    1,
                                                )
                                            }}% all-in
                                        </template>
                                        <template v-else>
                                            {{ feePercent }}% Plateful + Stripe
                                            processing ≈
                                            {{
                                                platefulEffectivePercent.toFixed(
                                                    1,
                                                )
                                            }}% all-in
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="saves"
                                class="mt-7 rounded-2xl bg-teal-700 p-6 text-white"
                                data-test="savings-result"
                            >
                                <p
                                    class="text-xs font-semibold tracking-wider text-teal-100/90 uppercase"
                                >
                                    You'd keep
                                </p>
                                <p
                                    class="mt-1 text-4xl font-bold tracking-tight tabular-nums"
                                >
                                    {{ money(savingsMonthly)
                                    }}<span
                                        class="text-lg font-semibold text-teal-100/90"
                                    >
                                        / month</span
                                    >
                                </p>
                                <p class="mt-1 text-sm text-teal-100/90">
                                    That's
                                    <span class="font-bold text-white">{{
                                        money(savingsYearly)
                                    }}</span>
                                    a year back in your pocket.
                                </p>
                                <p
                                    v-if="capActive"
                                    class="mt-3 border-t border-white/15 pt-3 text-xs leading-relaxed text-teal-100/80"
                                    data-test="cap-note"
                                >
                                    Includes our
                                    {{ money(feeCapDollars) }}/month fee cap —
                                    past that, extra orders only cost card
                                    processing.
                                </p>
                            </div>
                            <div
                                v-else
                                class="mt-7 rounded-2xl bg-stone-100 p-6"
                                data-test="savings-result-flat"
                            >
                                <p
                                    class="text-sm leading-relaxed text-stone-600"
                                >
                                    At {{ commissionPercent }}% you're already
                                    close to the real cost of direct ordering —
                                    the bigger win for you is owning your
                                    customer list instead of renting it.
                                </p>
                            </div>
                        </div>

                        <!-- CTA -->
                        <div class="rounded-3xl bg-teal-900 p-8 text-center">
                            <p class="text-lg font-bold text-white">
                                Want these numbers for your restaurant?
                            </p>
                            <p class="mt-2 text-sm text-teal-100/80">
                                Free setup — we build your menu and storefront
                                for you.
                            </p>
                            <!-- Plain anchor: /book may redirect off-site to
                                 an external scheduler, which an Inertia visit
                                 can't follow. -->
                            <a
                                v-if="bookingUrl"
                                :href="booking().url"
                                class="mt-6 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3 text-sm font-semibold text-teal-900 shadow-lg transition hover:bg-teal-50"
                                data-test="cta-book-call"
                            >
                                <CalendarCheck class="size-4" />
                                Book a 15-minute intro
                            </a>
                            <Link
                                v-else
                                :href="createSignup()"
                                class="mt-6 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3 text-sm font-semibold text-teal-900 shadow-lg transition hover:bg-teal-50"
                                data-test="cta-get-started"
                            >
                                Get started free
                                <ArrowRight class="size-4" />
                            </Link>
                            <p class="mt-4 text-xs text-teal-100/60">
                                <Link
                                    :href="forRestaurantsLanding()"
                                    class="underline underline-offset-4 transition hover:text-white"
                                    >See how Plateful works</Link
                                >
                            </p>
                        </div>
                    </div>
                </div>

                <p
                    class="mx-auto mt-8 max-w-3xl text-center text-xs leading-relaxed text-stone-400"
                >
                    Plateful charges {{ feePercent }}% of the food subtotal —
                    tax, tips, and delivery fees excluded — capped at
                    {{ money(feeCapDollars) }} per calendar month. The Plateful
                    figure above includes Stripe's standard card processing ({{
                        (stripeVariableRate * 100).toFixed(1)
                    }}% + {{ stripeFixedFeeCents }}¢ per order), which delivery
                    apps bundle into their commission and which isn't subject to
                    the cap. Marketplace apps also bring new customers; this
                    comparison is about your own repeat and direct orders.
                    Third-party pricing varies by plan and region.
                </p>
            </div>
        </section>

        <!-- Plain-language explainer (crawlable prose) -->
        <section class="border-t border-stone-900/5 bg-white py-16 sm:py-20">
            <div class="mx-auto max-w-3xl px-6">
                <h2
                    class="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl"
                >
                    How delivery app fees add up for Utah restaurants
                </h2>
                <div class="mt-6 space-y-5 leading-relaxed text-stone-600">
                    <p>
                        DoorDash and Uber Eats charge independent restaurants a
                        commission of 15–30% on every order, depending on the
                        plan — and that's on top of the menu markups and service
                        fees your customers already pay. For a restaurant doing
                        $6,000 a month through the apps at a 25% rate, that's
                        $1,500 a month in commissions — $18,000 a year.
                    </p>
                    <p>
                        Plateful is the other way to take online orders: your
                        own branded ordering site, where customers order and pay
                        you directly. Plateful charges a flat
                        {{ feePercent }}% of the food subtotal, capped at
                        {{ money(feeCapDollars) }} a month — a busy month never
                        gets expensive. Card processing goes through Stripe at
                        its standard rate, so your true all-in cost is around 8%
                        — roughly a third of what a 25% marketplace plan takes —
                        and past the cap it only falls as you grow.
                    </p>
                    <p>
                        Just as important: on your own storefront, the customer
                        is yours. You see who ordered, you can bring them back,
                        and nobody charges you rent on the relationship.
                        Plateful is built and run in American Fork, Utah — setup
                        is free, and we build your menu and storefront for you.
                    </p>
                </div>
            </div>
        </section>
    </MarketingLayout>
</template>
