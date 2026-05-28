<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    signup: {
        restaurantName: string;
        subdomain: string;
        status: string;
        submittedAt: string | null;
    } | null;
}>();
</script>

<template>
    <Head title="Application submitted" />

    <div class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <header class="border-b border-black/5 dark:border-white/10">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-5">
                <Link :href="home()" class="flex items-center gap-2">
                    <AppLogoIcon class-name="h-7 w-7 text-[#f53003] dark:text-[#FF4433]" />
                    <span class="text-lg font-semibold tracking-tight">Plateful</span>
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-2xl px-6 py-20 text-center">
            <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-[#f53003]/10 text-[#f53003] dark:bg-[#FF4433]/10 dark:text-[#FF4433]">
                <span class="text-xl">✓</span>
            </div>
            <h1 class="mt-6 text-3xl font-semibold tracking-tight">Application received</h1>

            <p v-if="signup" class="mt-4 text-[#1b1b18]/70 dark:text-[#EDEDEC]/70" data-test="pending-summary">
                Thanks — we have your application for
                <span class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ signup.restaurantName }}</span>
                ({{ signup.subdomain }}). We review applications within one business day and will email you the moment you're approved.
            </p>
            <p v-else class="mt-4 text-[#1b1b18]/70 dark:text-[#EDEDEC]/70">
                Thanks — we have your application. We'll email you the moment you're approved.
            </p>

            <div class="mt-10">
                <Link :href="home()" class="text-sm underline underline-offset-4">Back to home</Link>
            </div>
        </main>
    </div>
</template>
