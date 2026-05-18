<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import storefront from '@/routes/storefront';

defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: App.Data.MenuCategoryData[];
}>();

const formatPrice = (cents: number): string =>
    `$${(cents / 100).toFixed(2)}`;
</script>

<template>
    <div class="min-h-screen bg-neutral-50">
        <Head :title="restaurant.name" />

        <header
            class="px-6 py-10 text-white"
            :style="{ backgroundColor: restaurant.primaryColor ?? '#111827' }"
        >
            <div class="mx-auto max-w-4xl">
                <h1 class="text-3xl font-bold">{{ restaurant.name }}</h1>
                <p v-if="restaurant.description" class="mt-2 text-sm opacity-90">
                    {{ restaurant.description }}
                </p>
                <a
                    :href="storefront.home().url"
                    class="mt-4 inline-block text-xs underline opacity-75 hover:opacity-100"
                >
                    Home
                </a>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-6 py-10">
            <section
                v-for="category in categories"
                :key="category.id"
                class="mb-10"
            >
                <h2 class="mb-4 text-2xl font-semibold text-neutral-900">
                    {{ category.name }}
                </h2>
                <ul class="grid gap-4 md:grid-cols-2">
                    <li
                        v-for="item in category.items"
                        :key="item.id"
                        class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="font-medium text-neutral-900">
                                    {{ item.name }}
                                </h3>
                                <p
                                    v-if="item.description"
                                    class="mt-1 text-sm text-neutral-600"
                                >
                                    {{ item.description }}
                                </p>
                            </div>
                            <span class="whitespace-nowrap font-semibold text-neutral-900">
                                {{ formatPrice(item.priceCents) }}
                            </span>
                        </div>
                    </li>
                </ul>
            </section>
        </main>
    </div>
</template>
