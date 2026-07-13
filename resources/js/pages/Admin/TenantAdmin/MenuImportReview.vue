<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AlertTriangle, ArrowLeft, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive } from 'vue';

type DraftItem = {
    name: string;
    description: string;
    price: string; // dollars while editing; converted to cents on submit
    price_note: string | null;
};

type DraftCategory = {
    name: string;
    items: DraftItem[];
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    menuImport: {
        id: number;
        categories: Array<{
            name: string;
            items: Array<{
                name: string;
                description: string | null;
                price_cents: number;
                price_note: string | null;
            }>;
        }>;
        warnings: string[];
        itemCount: number;
        fileUrls: string[];
    };
}>();

const draft = reactive<{ categories: DraftCategory[] }>({
    categories: props.menuImport.categories.map((category) => ({
        name: category.name,
        items: category.items.map((item) => ({
            name: item.name,
            description: item.description ?? '',
            price: (item.price_cents / 100).toFixed(2),
            price_note: item.price_note,
        })),
    })),
});

const priceCents = (price: string): number => {
    const parsed = Number.parseFloat(price.replace(/[$,\s]/g, ''));
    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
};

const itemCount = computed(() =>
    draft.categories.reduce((sum, category) => sum + category.items.length, 0),
);

const missingPrices = computed(() =>
    draft.categories.reduce(
        (sum, category) =>
            sum + category.items.filter((item) => priceCents(item.price) <= 0).length,
        0,
    ),
);

const addItem = (category: DraftCategory): void => {
    category.items.push({ name: '', description: '', price: '', price_note: null });
};

const removeItem = (category: DraftCategory, index: number): void => {
    category.items.splice(index, 1);
};

const addCategory = (): void => {
    draft.categories.push({ name: '', items: [{ name: '', description: '', price: '', price_note: null }] });
};

const removeCategory = (index: number): void => {
    draft.categories.splice(index, 1);
};

type ConfirmCategory = {
    name: string;
    items: Array<{ name: string; description: string | null; price_cents: number }>;
};

const confirmForm = useForm<{ categories: ConfirmCategory[] }>({ categories: [] });

const submit = (): void => {
    confirmForm.categories = draft.categories
        .map((category) => ({
            name: category.name,
            items: category.items
                .filter((item) => item.name.trim() !== '')
                .map((item) => ({
                    name: item.name,
                    description: item.description.trim() === '' ? null : item.description,
                    price_cents: priceCents(item.price),
                })),
        }))
        .filter((category) => category.name.trim() !== '' && category.items.length > 0);

    confirmForm.post(
        `/${props.restaurant.subdomain}/menu-import/${props.menuImport.id}/confirm`,
    );
};

const errorMessages = computed(() =>
    Array.from(new Set(Object.values(confirmForm.errors))),
);

const discard = (): void => {
    if (!window.confirm('Discard this import? Your uploaded files and the extracted menu will be deleted.')) {
        return;
    }
    router.post(
        `/${props.restaurant.subdomain}/menu-import/${props.menuImport.id}/discard`,
    );
};
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="`Review your menu — ${restaurant.name}`" />

        <header class="sticky top-0 z-20 border-b border-border bg-card/95 backdrop-blur">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-3">
                    <a
                        :href="`/${restaurant.subdomain}/onboarding`"
                        class="text-muted-foreground hover:text-foreground"
                        aria-label="Back to setup"
                    >
                        <ArrowLeft class="size-5" />
                    </a>
                    <div>
                        <h1 class="text-lg font-semibold">Review your menu</h1>
                        <p class="text-xs text-muted-foreground">
                            Check names and prices, fix anything we misread, then import.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="text-sm text-muted-foreground underline hover:text-foreground"
                        @click="discard"
                    >
                        Discard
                    </button>
                    <Button
                        type="button"
                        :disabled="confirmForm.processing || itemCount === 0 || missingPrices > 0"
                        data-test="confirm-import-button"
                        @click="submit"
                    >
                        Import {{ itemCount }} {{ itemCount === 1 ? 'item' : 'items' }}
                    </Button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl space-y-6 px-6 py-8">
            <div
                v-if="missingPrices > 0"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                data-test="missing-prices-banner"
            >
                <strong class="font-semibold">{{ missingPrices }}
                    {{ missingPrices === 1 ? 'item needs' : 'items need' }} a price</strong>
                before you can import — they're highlighted below.
            </div>

            <div
                v-if="menuImport.warnings.length"
                class="space-y-1 rounded-lg border border-border bg-card p-4"
            >
                <p class="flex items-center gap-2 text-sm font-medium">
                    <AlertTriangle class="size-4 text-amber-500" />
                    Worth double-checking
                </p>
                <ul class="ml-6 list-disc text-sm text-muted-foreground">
                    <li v-for="(warning, i) in menuImport.warnings" :key="i">{{ warning }}</li>
                </ul>
            </div>

            <div v-if="menuImport.fileUrls.length" class="flex gap-2 overflow-x-auto pb-1">
                <a
                    v-for="(url, i) in menuImport.fileUrls"
                    :key="url"
                    :href="url"
                    target="_blank"
                    class="shrink-0"
                >
                    <img
                        :src="url"
                        :alt="`Uploaded menu page ${i + 1}`"
                        class="h-24 rounded-md border border-border object-cover"
                    />
                </a>
            </div>

            <p v-if="errorMessages.length" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                {{ errorMessages.join(' ') }}
            </p>

            <section
                v-for="(category, catIndex) in draft.categories"
                :key="catIndex"
                class="rounded-lg border border-border bg-card"
                :data-test="`review-category-${catIndex}`"
            >
                <div class="flex items-center justify-between gap-3 border-b border-border p-4">
                    <Input
                        v-model="category.name"
                        type="text"
                        class="max-w-xs font-semibold"
                        placeholder="Category name"
                    />
                    <button
                        type="button"
                        class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                        :aria-label="`Remove category ${category.name}`"
                        @click="removeCategory(catIndex)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>

                <div class="divide-y divide-border">
                    <div
                        v-for="(item, itemIndex) in category.items"
                        :key="itemIndex"
                        :class="[
                            'grid gap-2 p-4 sm:grid-cols-[1fr_auto]',
                            priceCents(item.price) <= 0 ? 'bg-red-50 dark:bg-red-950/30' : '',
                        ]"
                    >
                        <div class="space-y-2">
                            <Input
                                v-model="item.name"
                                type="text"
                                placeholder="Item name"
                                class="font-medium"
                            />
                            <Input
                                v-model="item.description"
                                type="text"
                                placeholder="Description (optional)"
                                class="text-sm"
                            />
                            <p
                                v-if="item.price_note"
                                class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200"
                            >
                                <AlertTriangle class="size-3" />
                                {{ item.price_note }}
                            </p>
                        </div>
                        <div class="flex items-start gap-2">
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-2.5 flex items-center text-sm text-muted-foreground"
                                    >$</span
                                >
                                <Input
                                    v-model="item.price"
                                    type="text"
                                    inputmode="decimal"
                                    class="w-24 pl-6 text-right"
                                    placeholder="0.00"
                                />
                            </div>
                            <button
                                type="button"
                                class="mt-1.5 rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                :aria-label="`Remove ${item.name || 'item'}`"
                                @click="removeItem(category, itemIndex)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="border-t border-border p-3">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground underline hover:text-foreground"
                        @click="addItem(category)"
                    >
                        <Plus class="size-3" />
                        Add item
                    </button>
                </div>
            </section>

            <Button type="button" variant="outline" @click="addCategory">
                <Plus class="size-4" />
                Add category
            </Button>

            <div class="sticky bottom-0 -mx-1 flex justify-end bg-background/80 py-3 backdrop-blur">
                <Button
                    type="button"
                    :disabled="confirmForm.processing || itemCount === 0 || missingPrices > 0"
                    @click="submit"
                >
                    {{ confirmForm.processing ? 'Importing…' : `Import ${itemCount} ${itemCount === 1 ? 'item' : 'items'}` }}
                </Button>
            </div>
        </main>
    </div>
</template>
