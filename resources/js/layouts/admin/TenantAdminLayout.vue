<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import TenantAdminSidebar from '@/components/admin/TenantAdminSidebar.vue';
import TwoFactorNudgeBanner from '@/components/admin/TwoFactorNudgeBanner.vue';
import {
    SidebarInset,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';

// Every tenant admin controller shares a `restaurant` page prop, so the
// layout reads it from the page rather than requiring pages to plumb it
// through — that's what lets it work as a persistent layout.
const page = usePage<{
    restaurant: App.Data.RestaurantData;
    sidebarOpen: boolean;
}>();

const restaurant = computed(() => page.props.restaurant);
</script>

<template>
    <SidebarProvider :default-open="page.props.sidebarOpen">
        <TenantAdminSidebar :restaurant="restaurant" />
        <SidebarInset>
            <header
                class="flex h-14 shrink-0 items-center gap-3 border-b border-border px-4 md:px-6"
            >
                <SidebarTrigger class="-ml-1" />
                <span class="truncate text-sm font-medium text-foreground">
                    {{ restaurant.name }}
                </span>
            </header>
            <div
                v-if="restaurant.isActive === false"
                class="border-b border-yellow-300 bg-yellow-100 text-yellow-900"
            >
                <div class="mx-auto w-full max-w-5xl px-4 py-3 text-sm md:px-6">
                    <strong class="font-semibold"
                        >This restaurant is currently deactivated.</strong
                    >
                    Customers cannot place orders. Contact your platform
                    administrator to reactivate.
                </div>
            </div>
            <TwoFactorNudgeBanner />
            <main class="mx-auto w-full max-w-5xl px-4 py-6 md:px-6 md:py-8">
                <slot />
            </main>
        </SidebarInset>
    </SidebarProvider>
</template>
