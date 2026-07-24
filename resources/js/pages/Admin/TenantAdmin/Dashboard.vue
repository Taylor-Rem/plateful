<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import PageHeader from '@/components/admin/PageHeader.vue';
import StatCard from '@/components/admin/StatCard.vue';
import { Button } from '@/components/ui/button';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="Dashboard" />
        <PageHeader title="Dashboard">
            <template #actions>
                <Button as-child variant="outline">
                    <a
                        :href="restaurant.publicUrl"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2"
                    >
                        Visit storefront <ExternalLink class="size-4" />
                    </a>
                </Button>
            </template>
        </PageHeader>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Orders today" />
            <StatCard label="Revenue" />
            <StatCard label="Avg ticket" />
            <StatCard label="Pending" />
        </div>
    </div>
</template>
