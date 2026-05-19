<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import storefront from '@/routes/storefront';

type BrandPalette = {
    primary: string;
    primaryForeground: string;
    secondary: string;
    secondaryForeground: string;
};

defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: App.Data.MenuCategoryData[];
    brand: BrandPalette;
}>();

const formatPrice = (cents: number): string =>
    `$${(cents / 100).toFixed(2)}`;
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="restaurant.name" />

        <header
            class="px-6 py-10"
            :style="{
                backgroundColor: 'var(--brand-primary)',
                color: 'var(--brand-primary-foreground)',
            }"
        >
            <div class="mx-auto flex max-w-4xl items-center gap-4">
                <img
                    v-if="restaurant.logoMediumUrl"
                    :src="restaurant.logoMediumUrl"
                    :alt="`${restaurant.name} logo`"
                    class="size-16 shrink-0 rounded-lg bg-white object-contain p-1"
                />
                <div>
                    <h1
                        class="text-3xl font-bold"
                        :style="{ color: 'var(--brand-primary-foreground)' }"
                    >
                        {{ restaurant.name }}
                    </h1>
                    <p v-if="restaurant.description" class="mt-2 text-sm opacity-90">
                        {{ restaurant.description }}
                    </p>
                </div>
            </div>
            <div class="mx-auto max-w-4xl">
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
                <h2
                    class="mb-4 inline-block border-b-2 pb-1 text-2xl font-semibold text-foreground"
                    :style="{ borderColor: 'var(--brand-primary)' }"
                >
                    {{ category.name }}
                </h2>
                <ul class="grid gap-4 md:grid-cols-2">
                    <li
                        v-for="item in category.items"
                        :key="item.id"
                        class="overflow-hidden rounded-lg border border-border bg-card shadow-sm transition hover:shadow-md"
                    >
                        <div
                            v-if="item.imageMediumUrl"
                            class="aspect-[4/3] w-full overflow-hidden bg-muted"
                        >
                            <img
                                :src="item.imageMediumUrl"
                                :alt="item.name"
                                class="size-full object-cover"
                            />
                        </div>
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div>
                                <h3 class="font-medium text-foreground">
                                    {{ item.name }}
                                </h3>
                                <p
                                    v-if="item.description"
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    {{ item.description }}
                                </p>
                            </div>
                            <span
                                class="whitespace-nowrap font-semibold"
                                :style="{ color: 'var(--brand-primary)' }"
                            >
                                {{ formatPrice(item.priceCents) }}
                            </span>
                        </div>
                    </li>
                </ul>
            </section>
        </main>
    </div>
</template>
