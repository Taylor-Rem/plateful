<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import { create as createSignup } from '@/actions/App/Http/Controllers/OwnerSignupController';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { initCalInline } from '@/lib/calEmbed';

const props = defineProps<{
    authUserName: string | null;
    hasAdminAccess: boolean;
    adminUrl: string;
    calLink: string;
    bookingUrl: string;
    longBookingUrl: string | null;
}>();

const signInUrl = computed(() => `${props.adminUrl}/login`);

onMounted(() => {
    initCalInline('#cal-booking', props.calLink);
});
</script>

<template>
    <Head title="Book a Call — Plateful">
        <meta
            name="description"
            content="Pick a time that works for you — a quick 15-minute call about what Plateful's flat-4% online ordering would look like for your restaurant. No pitch deck, just your numbers."
        />
    </Head>

    <MarketingLayout :admin-url="adminUrl">
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

            <div class="relative mx-auto max-w-6xl px-6 pt-16 pb-8 sm:pt-20">
                <p
                    class="text-sm font-semibold tracking-widest text-crimson-600 uppercase"
                >
                    Book a call
                </p>
                <h1
                    class="mt-4 max-w-2xl text-4xl leading-[1.1] font-bold tracking-tight text-stone-900 sm:text-5xl"
                >
                    Fifteen minutes,
                    <span class="text-teal-700">your numbers.</span>
                </h1>
                <p class="mt-5 max-w-xl text-lg leading-relaxed text-stone-600">
                    Pick a time below and you'll talk to the founder — no sales
                    team, no pitch deck. We'll look at what you pay the delivery
                    apps today and what your own storefront would keep.
                </p>
            </div>
        </section>

        <section class="relative pb-20">
            <div class="mx-auto max-w-6xl px-6">
                <div
                    class="overflow-hidden rounded-3xl bg-white p-2 shadow-xl ring-1 shadow-stone-900/5 ring-stone-900/5 sm:p-4"
                >
                    <div id="cal-booking" class="min-h-[620px] w-full"></div>
                </div>

                <p
                    class="mt-6 text-center text-sm text-stone-500"
                    data-test="booking-fallback"
                >
                    Calendar not loading?
                    <a
                        :href="bookingUrl"
                        class="font-semibold text-teal-700 underline-offset-4 hover:underline"
                        >Book directly on Cal.com</a
                    ><template v-if="longBookingUrl">
                        — or grab
                        <a
                            :href="longBookingUrl"
                            class="font-semibold text-teal-700 underline-offset-4 hover:underline"
                            data-test="booking-long-link"
                            >30 minutes</a
                        >
                        if you'd rather not rush</template
                    >.
                </p>
            </div>
        </section>
    </MarketingLayout>
</template>
