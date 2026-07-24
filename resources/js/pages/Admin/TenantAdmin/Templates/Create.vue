<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/components/admin/PageHeader.vue';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import TemplateForm from '@/pages/Admin/TenantAdmin/Templates/TemplateForm.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    template: App.Data.ItemTemplateData | null;
}>();

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="New template" />
        <PageHeader title="New item template" />
        <div class="mt-6 max-w-4xl">
            <TemplateForm :restaurant="restaurant" :template="template" />
        </div>
    </div>
</template>
