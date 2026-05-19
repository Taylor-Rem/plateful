<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ref } from 'vue';
import ItemConfiguratorModal from '@/pages/Storefront/components/ItemConfiguratorModal.vue';

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

const configuratorOpen = ref(false);
const activeItem = ref<App.Data.MenuItemData | null>(null);

const onItemClick = (item: App.Data.MenuItemData): void => {
    if (item.template) {
        activeItem.value = item;
        configuratorOpen.value = true;
        return;
    }
    router.post(
        `/cart/items/${item.id}`,
        { quantity: 1, option_ids: [] },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success(`Added ${item.name} to cart`);
            },
            onError: () => {
                toast.error('Could not add to cart.');
            },
        },
    );
};

const onAddToCart = (payload: { itemId: number; selections: Array<{ groupId: number; optionIds: number[] }>; unitPriceCents: number }): void => {
    const name = activeItem.value?.name ?? 'Item';
    const optionIds = payload.selections.flatMap((s) => s.optionIds);
    router.post(
        `/cart/items/${payload.itemId}`,
        { quantity: 1, option_ids: optionIds },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success(`Added ${name} to cart`);
            },
            onError: () => {
                toast.error('Could not add to cart.');
            },
        },
    );
};
</script>

<template>
    <div>
        <Head :title="restaurant.name" />

        <section
            class="px-6 py-12"
            :style="{
                backgroundColor: 'var(--brand-primary)',
                color: 'var(--brand-primary-foreground)',
            }"
        >
            <div class="mx-auto flex max-w-5xl items-center gap-5">
                <img
                    v-if="restaurant.logoMediumUrl"
                    :src="restaurant.logoMediumUrl"
                    :alt="`${restaurant.name} logo`"
                    class="size-20 shrink-0 rounded-lg bg-white object-contain p-1"
                />
                <div>
                    <h1
                        class="text-4xl font-bold tracking-tight"
                        :style="{ color: 'var(--brand-primary-foreground)' }"
                    >
                        {{ restaurant.name }}
                    </h1>
                    <p v-if="restaurant.description" class="mt-2 text-base opacity-90">
                        {{ restaurant.description }}
                    </p>
                </div>
            </div>
        </section>

        <main id="menu" class="mx-auto max-w-5xl px-6 py-10 scroll-mt-16">
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
                        class="cursor-pointer overflow-hidden rounded-lg border border-border bg-card text-left shadow-sm transition hover:shadow-md focus:outline-none focus:ring-2 focus:ring-ring"
                        tabindex="0"
                        role="button"
                        @click="onItemClick(item)"
                        @keydown.enter.prevent="onItemClick(item)"
                        @keydown.space.prevent="onItemClick(item)"
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
                                <p
                                    v-if="item.template"
                                    class="mt-2 text-xs uppercase tracking-wide text-muted-foreground"
                                >
                                    Customize
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

        <ItemConfiguratorModal
            v-if="activeItem"
            v-model:open="configuratorOpen"
            :item="activeItem"
            @add-to-cart="onAddToCart"
        />
    </div>
</template>
