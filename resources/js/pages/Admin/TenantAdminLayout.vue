<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link href="/" class="text-sm text-muted-foreground hover:text-foreground">←</Link>
                    <h1 class="text-lg font-semibold text-foreground">{{ restaurant.name }}</h1>
                </div>
                <div class="flex items-center gap-4">
                    <AppearanceTabs />
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Log out
                    </Link>
                </div>
            </div>
            <nav class="mx-auto flex max-w-5xl gap-6 px-6 pb-3 text-sm text-muted-foreground">
                <Link
                    :href="`/${restaurant.subdomain}/dashboard`"
                    class="hover:text-foreground"
                >
                    Dashboard
                </Link>
                <Link :href="`/${restaurant.subdomain}/menu`" class="hover:text-foreground">Menu</Link>
                <Link :href="`/${restaurant.subdomain}/orders`" class="hover:text-foreground">Orders</Link>
                <Link
                    :href="`/${restaurant.subdomain}/settings`"
                    class="hover:text-foreground"
                >
                    Settings
                </Link>
            </nav>
        </header>
        <main class="mx-auto max-w-5xl px-6 py-8">
            <slot />
        </main>
    </div>
</template>
