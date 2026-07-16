<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    create as createSignup,
    landing as forRestaurantsLanding,
} from '@/actions/App/Http/Controllers/OwnerSignupController';
import AppWordmark from '@/components/AppWordmark.vue';
import { home, privacy, support, terms } from '@/routes';

defineProps<{
    adminUrl?: string | null;
}>();
</script>

<template>
    <div class="min-h-screen bg-cream text-stone-900 antialiased">
        <header
            class="sticky top-0 z-40 border-b border-stone-900/5 bg-cream/85 backdrop-blur-md"
        >
            <div
                class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4"
            >
                <Link :href="home()" class="flex items-center">
                    <AppWordmark class-name="h-8 w-auto" />
                </Link>

                <nav class="flex items-center gap-1 text-sm">
                    <slot name="nav" />
                    <slot name="actions" />
                </nav>
            </div>
        </header>

        <main>
            <slot />
        </main>

        <footer class="bg-teal-950 py-14 text-teal-100/70">
            <div class="mx-auto max-w-6xl px-6">
                <div
                    class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr]"
                >
                    <div>
                        <p class="text-lg font-bold tracking-tight text-white">
                            plateful
                        </p>
                        <p class="mt-3 max-w-xs text-sm leading-relaxed">
                            Direct online ordering for independent restaurants.
                            No middlemen, no 30% commissions.
                        </p>
                    </div>

                    <nav aria-label="For diners" class="text-sm">
                        <p
                            class="text-xs font-semibold tracking-wider text-white/90 uppercase"
                        >
                            For diners
                        </p>
                        <ul class="mt-4 space-y-2.5">
                            <li>
                                <a
                                    :href="home().url + '#restaurants'"
                                    class="transition hover:text-white"
                                    >Find restaurants</a
                                >
                            </li>
                            <li>
                                <a
                                    :href="home().url + '#how-loyalty-works'"
                                    class="transition hover:text-white"
                                    >How loyalty works</a
                                >
                            </li>
                        </ul>
                    </nav>

                    <nav aria-label="For restaurants" class="text-sm">
                        <p
                            class="text-xs font-semibold tracking-wider text-white/90 uppercase"
                        >
                            For restaurants
                        </p>
                        <ul class="mt-4 space-y-2.5">
                            <li>
                                <Link
                                    :href="forRestaurantsLanding()"
                                    class="transition hover:text-white"
                                    >Why Plateful</Link
                                >
                            </li>
                            <li>
                                <a
                                    :href="
                                        forRestaurantsLanding().url + '#pricing'
                                    "
                                    class="transition hover:text-white"
                                    >Pricing</a
                                >
                            </li>
                            <li>
                                <Link
                                    :href="createSignup()"
                                    class="transition hover:text-white"
                                    >Get started</Link
                                >
                            </li>
                        </ul>
                    </nav>

                    <nav aria-label="Company" class="text-sm">
                        <p
                            class="text-xs font-semibold tracking-wider text-white/90 uppercase"
                        >
                            Company
                        </p>
                        <ul class="mt-4 space-y-2.5">
                            <li>
                                <Link
                                    :href="support()"
                                    class="transition hover:text-white"
                                    >Support</Link
                                >
                            </li>
                            <li>
                                <Link
                                    :href="terms()"
                                    class="transition hover:text-white"
                                    >Terms</Link
                                >
                            </li>
                            <li>
                                <Link
                                    :href="privacy()"
                                    class="transition hover:text-white"
                                    >Privacy</Link
                                >
                            </li>
                            <li v-if="adminUrl">
                                <a
                                    :href="adminUrl"
                                    class="transition hover:text-white"
                                    >Admin</a
                                >
                            </li>
                        </ul>
                    </nav>
                </div>

                <p
                    class="mt-12 border-t border-white/10 pt-6 text-xs text-teal-100/50"
                >
                    © {{ new Date().getFullYear() }} Plateful. All rights
                    reserved.
                </p>
            </div>
        </footer>
    </div>
</template>
