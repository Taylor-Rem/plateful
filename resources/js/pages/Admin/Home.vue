<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';

defineProps<{
    restaurants: App.Data.RestaurantData[];
    isSuperAdmin: boolean;
}>();
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Admin" />

        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <h1 class="text-lg font-semibold text-foreground">Plateful Admin</h1>
                <div class="flex items-center gap-4">
                    <AppearanceTabs />
                    <Link
                        :href="'/logout'"
                        method="post"
                        as="button"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Log out
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-10">
            <h2 class="mb-6 text-xl font-semibold text-foreground">
                {{ isSuperAdmin ? 'All restaurants' : 'Your restaurants' }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-if="isSuperAdmin"
                    href="/super/restaurants"
                    class="flex flex-col rounded-lg border border-primary/30 bg-primary/5 p-5 shadow-sm transition hover:border-primary/60"
                >
                    <span class="text-xs font-medium uppercase tracking-wide text-primary">Platform</span>
                    <span class="mt-2 text-lg font-semibold text-foreground">Manage platform</span>
                    <span class="mt-1 text-sm text-muted-foreground">Restaurants, admins, invitations</span>
                    <span class="mt-4 text-sm font-medium text-primary">Open →</span>
                </Link>

                <Link
                    v-for="restaurant in restaurants"
                    :key="restaurant.id"
                    :href="`/${restaurant.subdomain}/dashboard`"
                    class="flex flex-col rounded-lg border border-border bg-card p-5 shadow-sm transition hover:border-foreground/30"
                >
                    <img
                        v-if="restaurant.logoUrl"
                        :src="restaurant.logoUrl"
                        :alt="restaurant.name"
                        class="mb-3 h-12 w-12 rounded object-cover"
                    />
                    <span class="text-lg font-semibold text-foreground">{{ restaurant.name }}</span>
                    <span class="mt-1 text-sm text-muted-foreground">{{ restaurant.subdomain }}</span>
                    <span class="mt-4 text-sm font-medium text-foreground">Manage →</span>
                </Link>
            </div>

            <div
                v-if="restaurants.length === 0 && !isSuperAdmin"
                class="mt-6 rounded-lg border border-border bg-card p-6 text-muted-foreground"
            >
                You don't have access to any restaurants yet.
            </div>
        </main>
    </div>
</template>
