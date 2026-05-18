<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Orders`" />
        <h2 class="text-2xl font-semibold text-neutral-900">Orders</h2>
        <p class="mt-1 text-sm text-neutral-500">Order management coming soon.</p>
    </TenantAdminLayout>
</template>
