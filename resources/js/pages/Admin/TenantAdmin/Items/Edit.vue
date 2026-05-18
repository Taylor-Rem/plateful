<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import ItemForm from '@/pages/Admin/TenantAdmin/Items/ItemForm.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: { id: number; name: string }[];
    item: App.Data.MenuItemData;
}>();
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} — Edit ${item.name}`" />
        <h2 class="text-2xl font-semibold text-foreground">Edit item</h2>
        <div class="mt-6 max-w-3xl">
            <ItemForm :restaurant="restaurant" :categories="categories" :item="item" />
        </div>
    </TenantAdminLayout>
</template>
