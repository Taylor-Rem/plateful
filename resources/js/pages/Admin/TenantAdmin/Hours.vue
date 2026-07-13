<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import HoursEditor from '@/components/HoursEditor.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Hours`" />

        <div class="flex items-baseline justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-foreground">Hours</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    All times in {{ restaurant.timezone }}.
                </p>
            </div>
        </div>

        <div class="mt-6 max-w-2xl">
            <HoursEditor :restaurant="restaurant" show-timezone />
        </div>
    </TenantAdminLayout>
</template>
