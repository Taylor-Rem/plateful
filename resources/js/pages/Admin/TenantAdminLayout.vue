<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { computed } from 'vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const page = usePage<{ currentRestaurantRole: string | null }>();
const isAdmin = computed(() => page.props.currentRestaurantRole === 'admin');
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <div
            v-if="restaurant.isActive === false"
            class="border-b border-yellow-300 bg-yellow-100 text-yellow-900"
        >
            <div class="mx-auto max-w-5xl px-6 py-3 text-sm">
                <strong class="font-semibold">This restaurant is currently deactivated.</strong>
                Customers cannot place orders. Contact your platform administrator to reactivate.
            </div>
        </div>
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
                <Link :href="`/${restaurant.subdomain}/kitchen`" class="hover:text-foreground">Kitchen</Link>
                <Link
                    :href="`/${restaurant.subdomain}/hours`"
                    class="hover:text-foreground"
                >
                    Hours
                </Link>
                <template v-if="isAdmin">
                    <Link
                        :href="`/${restaurant.subdomain}/payouts`"
                        class="hover:text-foreground"
                    >
                        Payouts
                    </Link>
                    <Link
                        :href="`/${restaurant.subdomain}/members`"
                        class="hover:text-foreground"
                    >
                        Team
                    </Link>
                    <Link
                        :href="`/${restaurant.subdomain}/settings`"
                        class="hover:text-foreground"
                    >
                        Settings
                    </Link>
                </template>
            </nav>
        </header>
        <main class="mx-auto max-w-5xl px-6 py-8">
            <slot />
        </main>
    </div>
</template>
