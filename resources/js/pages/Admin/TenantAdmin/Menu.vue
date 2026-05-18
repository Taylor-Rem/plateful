<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: App.Data.MenuCategoryData[];
}>();

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Menu`" />
        <h2 class="text-2xl font-semibold text-neutral-900">Menu</h2>
        <p class="mt-1 text-sm text-neutral-500">Menu management coming soon.</p>

        <div class="mt-6 space-y-6">
            <section
                v-for="category in categories"
                :key="category.id"
                class="rounded-lg border border-neutral-200 bg-white p-4"
            >
                <h3 class="text-lg font-medium text-neutral-900">{{ category.name }}</h3>
                <ul class="mt-3 divide-y divide-neutral-100">
                    <li
                        v-for="item in category.items"
                        :key="item.id"
                        class="flex items-center justify-between py-2 text-sm"
                    >
                        <span class="text-neutral-800">{{ item.name }}</span>
                        <span class="text-neutral-600">{{ formatPrice(item.priceCents) }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </TenantAdminLayout>
</template>
