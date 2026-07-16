<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TemplateForm from '@/pages/Admin/TenantAdmin/Templates/TemplateForm.vue';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    template: App.Data.ItemTemplateData | null;
}>();
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} — New template`" />
        <h2 class="text-2xl font-semibold text-foreground">
            New item template
        </h2>
        <div class="mt-6 max-w-4xl">
            <TemplateForm :restaurant="restaurant" :template="template" />
        </div>
    </TenantAdminLayout>
</template>
