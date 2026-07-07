<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppWordmark from '@/components/AppWordmark.vue';

defineProps<{
    title: string;
    lastUpdated: string;
}>();
</script>

<template>
    <Head :title="`${title} — Plateful`" />

    <div
        class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
    >
        <header class="border-b border-black/5 dark:border-white/10">
            <div
                class="mx-auto flex max-w-3xl items-center justify-between px-6 py-5"
            >
                <Link href="/" class="flex items-center">
                    <AppWordmark class-name="h-8 w-auto" />
                </Link>
                <Link
                    href="/"
                    class="text-sm text-[#1b1b18]/70 hover:text-[#1b1b18] dark:text-[#EDEDEC]/70 dark:hover:text-[#EDEDEC]"
                >
                    ← Back to Plateful
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-6 py-16">
            <div class="border-b border-black/5 pb-8 dark:border-white/10">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    {{ title }}
                </h1>
                <p
                    class="mt-3 text-sm text-[#1b1b18]/60 dark:text-[#EDEDEC]/60"
                >
                    Last updated {{ lastUpdated }}
                </p>
            </div>

            <article class="legal-prose mt-10">
                <slot />
            </article>
        </main>

        <footer
            class="border-t border-black/5 py-10 text-sm text-[#1b1b18]/60 dark:border-white/10 dark:text-[#EDEDEC]/60"
        >
            <div
                class="mx-auto flex max-w-3xl flex-col items-center justify-between gap-3 px-6 sm:flex-row"
            >
                <div class="flex items-center gap-2">
                    <AppLogoIcon class-name="h-5 w-5" />
                    <span>© {{ new Date().getFullYear() }} Plateful</span>
                </div>
                <div class="flex items-center gap-5">
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
                    <Link
                        href="/"
                        class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                        >Home</Link
                    >
                </div>
            </div>
        </footer>
    </div>
</template>
