<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bot,
    Camera,
    ClipboardList,
    Image,
    MessageSquareText,
    Plug,
    Receipt,
    ShieldCheck,
    Utensils,
} from 'lucide-vue-next';
import { computed } from 'vue';
import {
    create as createSignup,
    landing as forRestaurantsLanding,
} from '@/actions/App/Http/Controllers/OwnerSignupController';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { booking, support } from '@/routes';

const props = defineProps<{
    authUserName: string | null;
    hasAdminAccess: boolean;
    adminUrl: string;
    canBookCall: boolean;
    mcpUrl: string;
    messageFloorCents: number;
    generatedPhotoCents: number;
    creditPacksCents: number[];
}>();

// Owners sign in on the admin host, so post-login `/` resolves to the admin
// console (not the diner home).
const signInUrl = computed(() => `${props.adminUrl}/login`);

const wholeDollars = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

const dollarsAndCents = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
});

// "$25" and "$1", but "$0.50" — never "$0.5".
function money(cents: number): string {
    return cents % 100 === 0
        ? wholeDollars.format(cents / 100)
        : dollarsAndCents.format(cents / 100);
}

const messageFloor = computed(() => money(props.messageFloorCents));
const generatedPhoto = computed(() => money(props.generatedPhotoCents));
const packs = computed(() => props.creditPacksCents.map(money));

/*
 * Every capability below is one tool on Plateful's MCP server (or one verb
 * of the CLI the text-us tier runs). If it is not on the server, it is not
 * on this list — that is the whole point of the "not yet" section.
 */
const capabilities = [
    {
        icon: Utensils,
        title: '86 an item, or bring it back',
        description:
            '"Sold out of the tomato soup" hides it from your menu right away. "Soup is back" shows it again.',
    },
    {
        icon: Camera,
        title: 'Put a photo on a menu item',
        description:
            'Send a photo and say which item it belongs to. It is resized, converted and live on the item in one step. Remove it the same way.',
    },
    {
        icon: Image,
        title: 'Logo, hero, about and gallery photos',
        description:
            'Swap the picture at the top of your site, replace your logo, or add a photo to your gallery with a caption.',
    },
    {
        icon: ClipboardList,
        title: 'Look up orders',
        description:
            '"What came in today?" or "Where is order ABC-12345?" Reads the kitchen board and any order, with its full timeline.',
    },
    {
        icon: Receipt,
        title: 'Read your menu and customers',
        description:
            'Your own assistant can read the full menu, hidden items included, and your customer list. Move an order from confirmed to preparing to ready.',
    },
    {
        icon: ShieldCheck,
        title: 'Scoped to your restaurant',
        description:
            'Every key reaches one restaurant and only the permissions you grant. Keys are rate limited and can be revoked at any time.',
    },
];

const notYet = [
    'Editing item names, descriptions or prices',
    'Adding or removing items and categories',
    'Hours, address, pickup and delivery settings',
    'Stripe, POS connections, refunds and customer accounts',
    'Your storefront design and colours',
];

const connectSteps = [
    {
        title: 'Get a key',
        description:
            'We create an API key for your restaurant with just the permissions you want — read the menu, change availability, upload photos, read orders. We do this with you today; just ask.',
    },
    {
        title: 'Connect your assistant',
        description:
            'Add Plateful as a remote MCP server in the assistant you already use, with the key as the bearer token. One command in Claude Code; a connector entry in others that support MCP over HTTP with an API key.',
    },
    {
        title: 'Ask',
        description:
            '"Mark the soup sold out." "Put this photo on the margherita." "What orders are in the kitchen?" Your assistant makes the call to Plateful; the storefront updates immediately.',
    },
];

const textSteps = [
    {
        title: 'We set you up',
        description:
            'You get a number you can text, or a Telegram or Discord bot, tied to your restaurant. We create the key and load your first credit pack with you.',
    },
    {
        title: 'Text it',
        description:
            'A photo with "this is the burger", or "86 the soup", or "what came in today?" You get an acknowledgement, then a reply when it is done.',
    },
    {
        title: 'Claude does it',
        description:
            'Claude runs the same Plateful API on your behalf and confirms. Anything it cannot do yet is forwarded to a person, and you are told so.',
    },
];
</script>

<template>
    <Head title="AI for Your Restaurant — Plateful">
        <meta
            name="description"
            content="Run your Plateful restaurant by text, or with the AI assistant you already pay for. 86 items, upload menu photos and check orders through Plateful's API — free with your own assistant, or on prepaid credits when you text us."
        />
    </Head>

    <MarketingLayout :admin-url="adminUrl">
        <template #nav>
            <a
                href="#what-it-does"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >What it does</a
            >
            <a
                href="#how-it-works"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >How it works</a
            >
            <a
                href="#pricing"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >Pricing</a
            >
            <Link
                :href="forRestaurantsLanding()"
                class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block"
                >For restaurants</Link
            >
        </template>

        <template #actions>
            <template v-if="authUserName">
                <span class="hidden px-2 text-stone-500 sm:inline-block">
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
                class="relative mx-auto max-w-6xl px-6 pt-20 pb-20 sm:pt-28 sm:pb-24"
            >
                <div class="grid items-center gap-14 lg:grid-cols-2">
                    <div>
                        <p
                            class="text-sm font-semibold tracking-widest text-crimson-600 uppercase"
                        >
                            AI for restaurants
                        </p>
                        <h1
                            class="mt-4 text-5xl leading-[1.05] font-bold tracking-tight text-stone-900 sm:text-6xl"
                        >
                            Text it,
                            <span class="text-teal-700">it's handled.</span>
                        </h1>
                        <p
                            class="mt-6 max-w-xl text-lg leading-relaxed text-stone-600"
                        >
                            86 the soup. Put this photo on the burger. What came
                            in today? Plateful's API lets Claude do the small,
                            constant jobs of running your menu. Bring the
                            assistant you already pay for and it's free. Or text
                            us and we do it for you on prepaid credits.
                        </p>
                        <div class="mt-9 flex flex-wrap items-center gap-3">
                            <a
                                v-if="canBookCall"
                                :href="booking().url"
                                class="inline-flex items-center gap-2 rounded-full bg-teal-700 px-6 py-3 text-sm font-semibold text-white shadow-md shadow-teal-900/20 transition hover:bg-teal-800"
                                data-test="ai-hero-book"
                            >
                                Book a 15-minute call
                                <ArrowRight class="size-4" />
                            </a>
                            <a
                                v-else
                                href="mailto:founder@plateful.fyi?subject=AI%20for%20my%20restaurant"
                                class="inline-flex items-center gap-2 rounded-full bg-teal-700 px-6 py-3 text-sm font-semibold text-white shadow-md shadow-teal-900/20 transition hover:bg-teal-800"
                                data-test="ai-hero-email"
                            >
                                Email us to get set up
                                <ArrowRight class="size-4" />
                            </a>
                            <a
                                href="#how-it-works"
                                class="inline-flex items-center rounded-full border border-stone-900/10 bg-white px-6 py-3 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-900/20 hover:text-stone-900"
                            >
                                See how it works
                            </a>
                        </div>
                        <p class="mt-4 text-sm text-stone-500">
                            Not on Plateful yet?
                            <Link
                                :href="createSignup()"
                                class="font-semibold text-teal-700 underline-offset-4 hover:underline"
                                >Get started free</Link
                            >
                            — this works on any restaurant account.
                        </p>
                    </div>

                    <!-- A sample exchange -->
                    <div
                        class="rounded-3xl bg-white p-6 shadow-xl ring-1 shadow-stone-900/5 ring-stone-900/5 sm:p-8"
                        aria-label="Example text conversation"
                    >
                        <p
                            class="text-xs font-semibold tracking-wider text-stone-500 uppercase"
                        >
                            A Tuesday at 4:40 pm
                        </p>
                        <ol class="mt-5 space-y-3 text-sm">
                            <li class="flex justify-end">
                                <p
                                    class="max-w-[85%] rounded-2xl rounded-br-md bg-teal-700 px-4 py-2.5 text-white"
                                >
                                    86 the tomato soup, we're out
                                </p>
                            </li>
                            <li class="flex">
                                <p
                                    class="max-w-[85%] rounded-2xl rounded-bl-md bg-stone-100 px-4 py-2.5 text-stone-800"
                                >
                                    Done — Tomato Basil Soup is hidden from your
                                    menu. Say "soup is back" when it is.
                                </p>
                            </li>
                            <li class="flex justify-end">
                                <div
                                    class="max-w-[85%] rounded-2xl rounded-br-md bg-teal-700 px-4 py-2.5 text-white"
                                >
                                    <span
                                        class="mb-2 flex h-20 items-center justify-center rounded-xl bg-teal-600/60 text-teal-100"
                                        aria-hidden="true"
                                    >
                                        <Camera class="size-6" />
                                    </span>
                                    this is the new smash burger
                                </div>
                            </li>
                            <li class="flex">
                                <p
                                    class="max-w-[85%] rounded-2xl rounded-bl-md bg-stone-100 px-4 py-2.5 text-stone-800"
                                >
                                    Photo is up on Smash Burger. Refresh your
                                    site to see it.
                                </p>
                            </li>
                        </ol>
                        <p
                            class="mt-6 border-t border-stone-100 pt-4 text-xs leading-relaxed text-stone-400"
                        >
                            Illustrative exchange. Every change is made through
                            the same Plateful API your admin console uses, so it
                            is live the moment the reply arrives.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- What it does -->
        <section
            id="what-it-does"
            class="border-t border-stone-900/5 bg-white py-20 sm:py-24"
        >
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-2xl">
                    <h2
                        class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl"
                    >
                        What it can do today
                    </h2>
                    <p class="mt-4 leading-relaxed text-stone-600">
                        Everything on this list is a tool on Plateful's API
                        right now. If it isn't here, it isn't built yet, and the
                        next section says so.
                    </p>
                </div>

                <div
                    class="mt-14 grid gap-8 sm:grid-cols-2 lg:grid-cols-3"
                    data-test="ai-capabilities"
                >
                    <div
                        v-for="capability in capabilities"
                        :key="capability.title"
                        class="rounded-2xl bg-cream/60 p-6 ring-1 ring-stone-900/5"
                    >
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-teal-700 text-white shadow-md shadow-teal-900/20"
                        >
                            <component :is="capability.icon" class="size-5" />
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-stone-900">
                            {{ capability.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-stone-600">
                            {{ capability.description }}
                        </p>
                    </div>
                </div>

                <!-- Not yet -->
                <div
                    id="not-yet"
                    class="mt-14 rounded-3xl border border-crimson-600/15 bg-crimson-50/60 p-8 sm:p-10"
                    data-test="ai-not-yet"
                >
                    <div class="grid gap-8 lg:grid-cols-[1fr_1.4fr]">
                        <div>
                            <h3
                                class="text-2xl font-bold tracking-tight text-stone-900"
                            >
                                What it can't do yet
                            </h3>
                            <p class="mt-3 leading-relaxed text-stone-600">
                                These still go through a person. Text them
                                anyway: anything the AI can't do is forwarded to
                                us, and you're told that's what happened.
                            </p>
                        </div>
                        <ul class="grid gap-3 sm:grid-cols-2">
                            <li
                                v-for="item in notYet"
                                :key="item"
                                class="flex items-start gap-3 rounded-xl bg-white/70 px-4 py-3 text-sm text-stone-700 ring-1 ring-stone-900/5"
                            >
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-crimson-600"
                                ></span>
                                {{ item }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section id="how-it-works" class="py-20 sm:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-2xl">
                    <h2
                        class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl"
                    >
                        Two ways in
                    </h2>
                    <p class="mt-4 leading-relaxed text-stone-600">
                        Both run on the same Plateful API with the same
                        permissions. The difference is whose assistant does the
                        work, and who pays for its time.
                    </p>
                </div>

                <div class="mt-14 grid gap-8 lg:grid-cols-2">
                    <!-- Connect -->
                    <div
                        class="rounded-3xl bg-white p-8 ring-1 ring-stone-900/5 sm:p-10"
                        data-test="ai-connect"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border border-teal-700/15 bg-teal-50 px-3.5 py-1.5 text-xs font-semibold tracking-wide text-teal-800"
                        >
                            <Plug class="size-3.5" />
                            Bring your own assistant
                        </span>
                        <h3
                            class="mt-5 text-2xl font-bold tracking-tight text-stone-900"
                        >
                            Connect the AI you already use
                        </h3>
                        <ol class="mt-8 space-y-7">
                            <li
                                v-for="(step, index) in connectSteps"
                                :key="step.title"
                                class="flex gap-4"
                            >
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-700 text-sm font-bold text-white shadow-md shadow-teal-900/20"
                                >
                                    {{ index + 1 }}
                                </div>
                                <div>
                                    <h4
                                        class="text-base font-semibold text-stone-900"
                                    >
                                        {{ step.title }}
                                    </h4>
                                    <p
                                        class="mt-1.5 text-sm leading-relaxed text-stone-600"
                                    >
                                        {{ step.description }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                        <div
                            class="mt-8 rounded-2xl bg-stone-900 p-5 text-xs leading-relaxed text-stone-200"
                        >
                            <p
                                class="font-semibold tracking-wider text-stone-400 uppercase"
                            >
                                Claude Code
                            </p>
                            <pre
                                class="mt-2 overflow-x-auto font-mono whitespace-pre"
                                data-test="ai-mcp-command"
                            ><code>claude mcp add --transport http plateful {{ mcpUrl }} \
  --header "Authorization: Bearer pfk_live_…"</code></pre>
                        </div>
                    </div>

                    <!-- Text -->
                    <div
                        class="rounded-3xl bg-teal-900 p-8 text-teal-50 ring-1 ring-teal-950/40 sm:p-10"
                        data-test="ai-text"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-semibold tracking-wide text-teal-100"
                        >
                            <MessageSquareText class="size-3.5" />
                            Text us
                        </span>
                        <h3
                            class="mt-5 text-2xl font-bold tracking-tight text-white"
                        >
                            No assistant? Text us and it's done
                        </h3>
                        <ol class="mt-8 space-y-7">
                            <li
                                v-for="(step, index) in textSteps"
                                :key="step.title"
                                class="flex gap-4"
                            >
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold text-teal-900 shadow-md"
                                >
                                    {{ index + 1 }}
                                </div>
                                <div>
                                    <h4
                                        class="text-base font-semibold text-white"
                                    >
                                        {{ step.title }}
                                    </h4>
                                    <p
                                        class="mt-1.5 text-sm leading-relaxed text-teal-100/80"
                                    >
                                        {{ step.description }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                        <p
                            class="mt-8 rounded-2xl bg-white/10 p-5 text-xs leading-relaxed text-teal-100/80"
                        >
                            Works over SMS, Telegram or Discord. If something
                            goes wrong mid-task you get a plain-language note
                            and we get the details, so nothing is silently
                            half-done.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pricing -->
        <section
            id="pricing"
            class="border-t border-stone-900/5 bg-white py-20 sm:py-24"
        >
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-2xl">
                    <h2
                        class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl"
                    >
                        Pricing
                    </h2>
                    <p class="mt-4 leading-relaxed text-stone-600">
                        Separate from Plateful's 4% per order, which never
                        changes. You pay for an assistant's time only when it's
                        ours.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 lg:grid-cols-2">
                    <div
                        class="flex flex-col rounded-3xl bg-cream/60 p-8 ring-1 ring-stone-900/5 sm:p-10"
                        data-test="ai-tier-byo"
                    >
                        <p
                            class="text-xs font-semibold tracking-wider text-stone-500 uppercase"
                        >
                            Bring your own assistant
                        </p>
                        <div class="mt-4 flex items-baseline gap-2">
                            <span
                                class="text-6xl font-bold tracking-tight text-teal-700"
                                >Free</span
                            >
                        </div>
                        <p class="mt-3 text-sm text-stone-600">
                            For every restaurant on Plateful.
                        </p>
                        <ul class="mt-8 space-y-3 text-sm text-stone-700">
                            <li class="flex items-start gap-3">
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-teal-600"
                                ></span>
                                Your assistant's subscription pays for the work.
                                Plateful charges nothing for the key or the
                                calls.
                            </li>
                            <li class="flex items-start gap-3">
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-teal-600"
                                ></span>
                                One key per assistant, scoped to your restaurant
                                and to the permissions you choose.
                            </li>
                            <li class="flex items-start gap-3">
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-teal-600"
                                ></span>
                                Revoke a key and its next request is refused.
                            </li>
                            <li class="flex items-start gap-3">
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-teal-600"
                                ></span>
                                Photo generation is up to your assistant; we
                                only store what it sends.
                            </li>
                        </ul>
                        <p class="mt-8 text-sm text-stone-500">
                            Setup today: ask us and we create the key with you.
                        </p>
                    </div>

                    <div
                        class="flex flex-col rounded-3xl bg-white p-8 ring-2 ring-teal-700/60 sm:p-10"
                        data-test="ai-tier-text"
                    >
                        <p
                            class="text-xs font-semibold tracking-wider text-teal-700 uppercase"
                        >
                            Text us
                        </p>
                        <div class="mt-4 flex items-baseline gap-2">
                            <span
                                class="text-6xl font-bold tracking-tight text-teal-700"
                                >{{ messageFloor }}</span
                            >
                            <span class="text-lg text-stone-500"
                                >per message, and up</span
                            >
                        </div>
                        <p class="mt-3 text-sm text-stone-600">
                            Prepaid credits. No subscription.
                        </p>
                        <ul class="mt-8 space-y-3 text-sm text-stone-700">
                            <li class="flex items-start gap-3">
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-teal-600"
                                ></span>
                                Most messages cost {{ messageFloor }}. A bigger
                                job can cost a few dollars; the price is what
                                the AI's time cost us, marked up.
                            </li>
                            <li class="flex items-start gap-3">
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-teal-600"
                                ></span>
                                A photo we generate for you is
                                {{ generatedPhoto }} on top of the message.
                                Photos you send, and stock photos, cost nothing
                                beyond the message.
                            </li>
                            <li class="flex items-start gap-3">
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-teal-600"
                                ></span>
                                Credit packs of {{ packs.join(' and ') }}.
                                Credits don't expire.
                            </li>
                            <li class="flex items-start gap-3">
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-teal-600"
                                ></span>
                                Every message is logged; ask us for your usage
                                any time.
                            </li>
                        </ul>
                        <p class="mt-8 text-sm text-stone-500">
                            Setup today: credits can't be bought inside Plateful
                            yet. Book a call or email us, and we set up your
                            number and first pack with you.
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
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-semibold tracking-wide text-teal-100"
                        >
                            <Bot class="size-3.5" />
                            Get set up
                        </span>
                        <h2
                            class="mt-5 text-3xl font-bold tracking-tight text-white sm:text-4xl"
                        >
                            Ready to stop doing the small stuff?
                        </h2>
                        <p class="mt-4 text-teal-100/80">
                            Fifteen minutes with the founder to pick a tier,
                            create your key and, if you're texting, load your
                            first credits. No sales team.
                        </p>
                        <!-- Plain anchor: /book may redirect off-site to an
                             external scheduler, which an Inertia visit can't
                             follow. -->
                        <a
                            v-if="canBookCall"
                            :href="booking().url"
                            class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3 text-sm font-semibold text-teal-900 shadow-lg transition hover:bg-teal-50"
                            data-test="ai-cta-book"
                        >
                            Book a 15-minute call
                            <ArrowRight class="size-4" />
                        </a>
                        <a
                            v-else
                            href="mailto:founder@plateful.fyi?subject=AI%20for%20my%20restaurant"
                            class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3 text-sm font-semibold text-teal-900 shadow-lg transition hover:bg-teal-50"
                            data-test="ai-cta-email"
                        >
                            Email founder@plateful.fyi
                            <ArrowRight class="size-4" />
                        </a>
                        <p class="mt-4 text-sm text-teal-100/80">
                            Questions first? See
                            <Link
                                :href="support()"
                                class="font-semibold text-white underline underline-offset-4 transition hover:text-teal-50"
                                >Support</Link
                            >.
                        </p>
                    </div>
                </div>
            </div>
        </section>
    </MarketingLayout>
</template>
