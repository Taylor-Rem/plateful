<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/components/admin/PageHeader.vue';
import HoursEditor from '@/components/HoursEditor.vue';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="Hours" />

        <PageHeader
            title="Hours"
            :description="`All times in ${restaurant.timezone}.`"
        />

        <div class="mt-6 max-w-2xl">
            <HoursEditor :restaurant="restaurant" show-timezone />
        </div>
    </div>
</template>
