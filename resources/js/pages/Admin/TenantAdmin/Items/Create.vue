<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import ItemForm from '@/pages/Admin/TenantAdmin/Items/ItemForm.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: { id: number; name: string }[];
    templates: App.Data.ItemTemplateData[];
    item: App.Data.MenuItemData | null;
}>();
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} — New item`" />
        <h2 class="text-2xl font-semibold text-foreground">New item</h2>
        <div class="mt-6 max-w-3xl">
            <ItemForm :restaurant="restaurant" :categories="categories" :templates="templates" :item="item" />
        </div>
    </TenantAdminLayout>
</template>
