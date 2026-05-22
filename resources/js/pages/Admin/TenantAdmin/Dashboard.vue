<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import { Button } from '@/components/ui/button';
import { ExternalLink } from 'lucide-vue-next';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Dashboard`" />
        <div class="flex items-center justify-between">
            <h2 class="text-2xl font-semibold text-foreground">Dashboard</h2>
            <Button as-child variant="outline">
                <a :href="restaurant.publicUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-2">
                    Visit storefront <ExternalLink class="size-4" />
                </a>
            </Button>
        </div>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-border bg-card p-4">
                <div class="text-xs uppercase tracking-wide text-muted-foreground">Orders today</div>
                <div class="mt-2 text-2xl font-semibold text-foreground">—</div>
            </div>
            <div class="rounded-lg border border-border bg-card p-4">
                <div class="text-xs uppercase tracking-wide text-muted-foreground">Revenue</div>
                <div class="mt-2 text-2xl font-semibold text-foreground">—</div>
            </div>
            <div class="rounded-lg border border-border bg-card p-4">
                <div class="text-xs uppercase tracking-wide text-muted-foreground">Avg ticket</div>
                <div class="mt-2 text-2xl font-semibold text-foreground">—</div>
            </div>
            <div class="rounded-lg border border-border bg-card p-4">
                <div class="text-xs uppercase tracking-wide text-muted-foreground">Pending</div>
                <div class="mt-2 text-2xl font-semibold text-foreground">—</div>
            </div>
        </div>
    </TenantAdminLayout>
</template>
