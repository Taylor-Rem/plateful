<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    restaurants: App.Data.RestaurantData[];
    isSuperAdmin: boolean;
}>();
</script>

<template>
    <div class="min-h-screen bg-neutral-50">
        <Head title="Admin" />

        <header class="border-b border-neutral-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <h1 class="text-lg font-semibold text-neutral-900">Plateful Admin</h1>
                <Link
                    :href="'/logout'"
                    method="post"
                    as="button"
                    class="text-sm text-neutral-600 hover:text-neutral-900"
                >
                    Log out
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-10">
            <h2 class="mb-6 text-xl font-semibold text-neutral-900">
                {{ isSuperAdmin ? 'All restaurants' : 'Your restaurants' }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-if="isSuperAdmin"
                    href="/super/restaurants"
                    class="flex flex-col rounded-lg border border-indigo-200 bg-indigo-50 p-5 shadow-sm transition hover:border-indigo-400"
                >
                    <span class="text-xs font-medium uppercase tracking-wide text-indigo-700">Platform</span>
                    <span class="mt-2 text-lg font-semibold text-indigo-900">Manage platform</span>
                    <span class="mt-1 text-sm text-indigo-700">Restaurants, admins, invitations</span>
                    <span class="mt-4 text-sm font-medium text-indigo-700">Open →</span>
                </Link>

                <Link
                    v-for="restaurant in restaurants"
                    :key="restaurant.id"
                    :href="`/${restaurant.subdomain}/dashboard`"
                    class="flex flex-col rounded-lg border border-neutral-200 bg-white p-5 shadow-sm transition hover:border-neutral-400"
                >
                    <img
                        v-if="restaurant.logoUrl"
                        :src="restaurant.logoUrl"
                        :alt="restaurant.name"
                        class="mb-3 h-12 w-12 rounded object-cover"
                    />
                    <span class="text-lg font-semibold text-neutral-900">{{ restaurant.name }}</span>
                    <span class="mt-1 text-sm text-neutral-500">{{ restaurant.subdomain }}</span>
                    <span class="mt-4 text-sm font-medium text-neutral-700">Manage →</span>
                </Link>
            </div>

            <div
                v-if="restaurants.length === 0 && !isSuperAdmin"
                class="rounded-lg border border-neutral-200 bg-white p-6 text-neutral-600"
            >
                You don't have access to any restaurants yet.
            </div>
        </main>
    </div>
</template>
