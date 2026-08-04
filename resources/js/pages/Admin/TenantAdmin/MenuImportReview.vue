<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    confirm as menuImportConfirm,
    discard as menuImportDiscard,
} from '@/routes/admin/restaurant/menuImport';

type DraftItem = {
    name: string;
    description: string;
    price: string; // dollars while editing; converted to cents on submit
    price_note: string | null;
    option_set: string | null;
};

type DraftCategory = {
    name: string;
    items: DraftItem[];
};

type DraftOption = {
    name: string;
    priceDelta: string; // dollars while editing; converted to cents on submit
    is_default: boolean;
};

type DraftGroup = {
    name: string;
    min_selections: number;
    max_selections: number | null;
    options: DraftOption[];
};

type DraftOptionSet = {
    name: string;
    groups: DraftGroup[];
};

type ImportOptionSet = {
    name: string;
    groups: Array<{
        name: string;
        min_selections: number;
        max_selections: number | null;
        options: Array<{
            name: string;
            price_delta_cents: number;
            is_default: boolean;
        }>;
    }>;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    backUrl: string;
    existingItemCount: number;
    menuImport: {
        id: number;
        categories: Array<{
            name: string;
            items: Array<{
                name: string;
                description: string | null;
                price_cents: number;
                price_note: string | null;
                option_set?: string | null;
            }>;
        }>;
        optionSets: ImportOptionSet[];
        warnings: string[];
        itemCount: number;
        fileUrls: string[];
    };
}>();

const draft = reactive<{
    categories: DraftCategory[];
    optionSets: DraftOptionSet[];
}>({
    categories: props.menuImport.categories.map((category) => ({
        name: category.name,
        items: category.items.map((item) => ({
            name: item.name,
            description: item.description ?? '',
            price: (item.price_cents / 100).toFixed(2),
            price_note: item.price_note,
            option_set: item.option_set ?? null,
        })),
    })),
    optionSets: props.menuImport.optionSets.map((set) => ({
        name: set.name,
        groups: set.groups.map((group) => ({
            name: group.name,
            min_selections: group.min_selections,
            max_selections: group.max_selections,
            options: group.options.map((option) => ({
                name: option.name,
                priceDelta: (option.price_delta_cents / 100).toFixed(2),
                is_default: option.is_default,
            })),
        })),
    })),
});

const priceCents = (price: string): number => {
    const parsed = Number.parseFloat(price.replace(/[$,\s]/g, ''));

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
};

const deltaCents = (price: string): number => {
    const parsed = Number.parseFloat(price.replace(/[$,\s]/g, ''));

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
};

const groupRule = (group: DraftGroup): string => {
    if (group.min_selections >= 1 && group.max_selections === 1) {
        return 'Pick exactly 1';
    }

    if (group.min_selections > 0 && group.max_selections !== null) {
        return `Pick ${group.min_selections}–${group.max_selections}`;
    }

    if (group.max_selections !== null) {
        return `Pick up to ${group.max_selections}`;
    }

    if (group.min_selections > 0) {
        return `Pick at least ${group.min_selections}`;
    }

    return 'Optional';
};

const removeOptionSet = (index: number): void => {
    const removed = draft.optionSets[index];
    draft.optionSets.splice(index, 1);

    for (const category of draft.categories) {
        for (const item of category.items) {
            if (item.option_set === removed.name) {
                item.option_set = null;
            }
        }
    }
};

const removeGroup = (set: DraftOptionSet, index: number): void => {
    set.groups.splice(index, 1);

    if (set.groups.length === 0) {
        removeOptionSet(draft.optionSets.indexOf(set));
    }
};

const removeOption = (
    set: DraftOptionSet,
    group: DraftGroup,
    index: number,
): void => {
    group.options.splice(index, 1);

    if (group.options.length === 0) {
        removeGroup(set, set.groups.indexOf(group));
    }
};

const renameOptionSet = (set: DraftOptionSet, name: string): void => {
    const previous = set.name;
    set.name = name;

    for (const category of draft.categories) {
        for (const item of category.items) {
            if (item.option_set === previous) {
                item.option_set = name;
            }
        }
    }
};

const itemCount = computed(() =>
    draft.categories.reduce((sum, category) => sum + category.items.length, 0),
);

const missingPrices = computed(() =>
    draft.categories.reduce(
        (sum, category) =>
            sum +
            category.items.filter((item) => priceCents(item.price) <= 0).length,
        0,
    ),
);

const addItem = (category: DraftCategory): void => {
    category.items.push({
        name: '',
        description: '',
        price: '',
        price_note: null,
        option_set: null,
    });
};

const removeItem = (category: DraftCategory, index: number): void => {
    category.items.splice(index, 1);
};

const addCategory = (): void => {
    draft.categories.push({
        name: '',
        items: [
            {
                name: '',
                description: '',
                price: '',
                price_note: null,
                option_set: null,
            },
        ],
    });
};

const removeCategory = (index: number): void => {
    draft.categories.splice(index, 1);
};

type ConfirmCategory = {
    name: string;
    items: Array<{
        name: string;
        description: string | null;
        price_cents: number;
        option_set: string | null;
    }>;
};

type ConfirmOptionSet = {
    name: string;
    groups: Array<{
        name: string;
        min_selections: number;
        max_selections: number | null;
        options: Array<{
            name: string;
            price_delta_cents: number;
            is_default: boolean;
        }>;
    }>;
};

const confirmForm = useForm<{
    categories: ConfirmCategory[];
    option_sets: ConfirmOptionSet[];
}>({
    categories: [],
    option_sets: [],
});

const submit = (): void => {
    const optionSets = draft.optionSets
        .map((set) => ({
            name: set.name.trim(),
            groups: set.groups
                .map((group) => ({
                    name: group.name.trim(),
                    min_selections: Math.min(
                        group.min_selections,
                        group.options.length,
                    ),
                    max_selections: group.max_selections,
                    options: group.options
                        .filter((option) => option.name.trim() !== '')
                        .map((option) => ({
                            name: option.name,
                            price_delta_cents: deltaCents(option.priceDelta),
                            is_default: option.is_default,
                        })),
                }))
                .filter(
                    (group) => group.name !== '' && group.options.length > 0,
                ),
        }))
        .filter((set) => set.name !== '' && set.groups.length > 0);

    const setNames = new Set(optionSets.map((set) => set.name));

    confirmForm.option_sets = optionSets;
    confirmForm.categories = draft.categories
        .map((category) => ({
            name: category.name,
            items: category.items
                .filter((item) => item.name.trim() !== '')
                .map((item) => ({
                    name: item.name,
                    description:
                        item.description.trim() === ''
                            ? null
                            : item.description,
                    price_cents: priceCents(item.price),
                    option_set:
                        item.option_set && setNames.has(item.option_set)
                            ? item.option_set
                            : null,
                })),
        }))
        .filter(
            (category) =>
                category.name.trim() !== '' && category.items.length > 0,
        );

    confirmForm.post(
        menuImportConfirm.url({
            restaurant: props.restaurant.subdomain,
            menuImport: props.menuImport.id,
        }),
    );
};

const errorMessages = computed(() =>
    Array.from(new Set(Object.values(confirmForm.errors))),
);

const confirmLabel = computed(() => {
    const noun = itemCount.value === 1 ? 'item' : 'items';

    return props.existingItemCount > 0
        ? `Replace menu with ${itemCount.value} ${noun}`
        : `Import ${itemCount.value} ${noun}`;
});

const discard = (): void => {
    if (
        !window.confirm(
            'Discard this import? Your uploaded files and the extracted menu will be deleted.',
        )
    ) {
        return;
    }

    router.post(
        menuImportDiscard.url({
            restaurant: props.restaurant.subdomain,
            menuImport: props.menuImport.id,
        }),
    );
};
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="`Review your menu — ${restaurant.name}`" />

        <header
            class="sticky top-0 z-20 border-b border-border bg-card/95 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6"
            >
                <div class="flex items-center gap-3">
                    <a
                        :href="backUrl"
                        class="text-muted-foreground hover:text-foreground"
                        aria-label="Back"
                    >
                        <ArrowLeft class="size-5" />
                    </a>
                    <div>
                        <h1 class="text-lg font-semibold">Review your menu</h1>
                        <p
                            class="hidden text-xs text-muted-foreground sm:block"
                        >
                            Check names and prices, fix anything we misread,
                            then import.
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
                        :disabled="
                            confirmForm.processing ||
                            itemCount === 0 ||
                            missingPrices > 0
                        "
                        data-test="confirm-import-button"
                        @click="submit"
                    >
                        {{ confirmLabel }}
                    </Button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
            <div
                v-if="existingItemCount > 0"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                data-test="replace-menu-banner"
            >
                <strong class="font-semibold">
                    Confirming will replace your current menu ({{
                        existingItemCount
                    }}
                    {{ existingItemCount === 1 ? 'item' : 'items' }}).
                </strong>
                Past orders keep their history, but items customers still have
                in open carts will be removed from those carts.
            </div>

            <div
                v-if="missingPrices > 0"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                data-test="missing-prices-banner"
            >
                <strong class="font-semibold"
                    >{{ missingPrices }}
                    {{ missingPrices === 1 ? 'item needs' : 'items need' }} a
                    price</strong
                >
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
                    <li v-for="(warning, i) in menuImport.warnings" :key="i">
                        {{ warning }}
                    </li>
                </ul>
            </div>

            <div
                v-if="menuImport.fileUrls.length"
                class="flex gap-2 overflow-x-auto pb-1"
            >
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

            <p
                v-if="errorMessages.length"
                class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"
            >
                {{ errorMessages.join(' ') }}
            </p>

            <section
                v-if="draft.optionSets.length > 0"
                class="space-y-4"
                data-test="review-option-sets"
            >
                <div>
                    <h2 class="text-base font-semibold">
                        Customization options
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        We read these choices and add-ons from your menu. Assign
                        them to items below — you can restructure them anytime
                        in the menu builder after importing.
                    </p>
                </div>

                <div
                    v-for="(set, setIndex) in draft.optionSets"
                    :key="setIndex"
                    class="rounded-lg border border-border bg-card"
                    :data-test="`review-option-set-${setIndex}`"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-border p-4"
                    >
                        <Input
                            :model-value="set.name"
                            type="text"
                            class="max-w-xs font-semibold"
                            placeholder="Option set name"
                            @update:model-value="
                                (v) => renameOptionSet(set, String(v))
                            "
                        />
                        <button
                            type="button"
                            class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                            :aria-label="`Remove option set ${set.name}`"
                            @click="removeOptionSet(setIndex)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>

                    <div class="divide-y divide-border">
                        <div
                            v-for="(group, groupIndex) in set.groups"
                            :key="groupIndex"
                            class="p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <div
                                    class="flex min-w-0 flex-1 flex-wrap items-baseline gap-2 text-sm"
                                >
                                    <Input
                                        v-model="group.name"
                                        type="text"
                                        class="w-full text-sm font-medium sm:max-w-48"
                                        placeholder="Group name"
                                    />
                                    <span
                                        class="text-xs whitespace-nowrap text-muted-foreground"
                                    >
                                        {{ groupRule(group) }}
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                    :aria-label="`Remove group ${group.name}`"
                                    @click="removeGroup(set, groupIndex)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>

                            <div class="mt-2 space-y-1.5">
                                <div
                                    v-for="(
                                        option, optionIndex
                                    ) in group.options"
                                    :key="optionIndex"
                                    class="flex items-center gap-2"
                                >
                                    <Input
                                        v-model="option.name"
                                        type="text"
                                        class="flex-1 text-sm"
                                        placeholder="Option name"
                                    />
                                    <span
                                        v-if="option.is_default"
                                        class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        Default
                                    </span>
                                    <div class="relative">
                                        <span
                                            class="absolute inset-y-0 left-2.5 flex items-center text-sm text-muted-foreground"
                                            >+$</span
                                        >
                                        <Input
                                            v-model="option.priceDelta"
                                            type="text"
                                            inputmode="decimal"
                                            class="w-24 pl-8 text-right text-sm"
                                            placeholder="0.00"
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                        :aria-label="`Remove option ${option.name || 'option'}`"
                                        @click="
                                            removeOption(
                                                set,
                                                group,
                                                optionIndex,
                                            )
                                        "
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-for="(category, catIndex) in draft.categories"
                :key="catIndex"
                class="rounded-lg border border-border bg-card"
                :data-test="`review-category-${catIndex}`"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-border p-4"
                >
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
                            priceCents(item.price) <= 0
                                ? 'bg-red-50 dark:bg-red-950/30'
                                : '',
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
                            <label
                                v-if="draft.optionSets.length > 0"
                                class="flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                Options
                                <select
                                    v-model="item.option_set"
                                    class="rounded-md border border-input bg-background px-2 py-1 text-xs text-foreground"
                                >
                                    <option :value="null">None</option>
                                    <option
                                        v-for="set in draft.optionSets"
                                        :key="set.name"
                                        :value="set.name"
                                    >
                                        {{ set.name }}
                                    </option>
                                </select>
                            </label>
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

            <div
                class="sticky bottom-0 -mx-1 flex justify-end bg-background/80 py-3 backdrop-blur"
            >
                <Button
                    type="button"
                    :disabled="
                        confirmForm.processing ||
                        itemCount === 0 ||
                        missingPrices > 0
                    "
                    @click="submit"
                >
                    {{ confirmForm.processing ? 'Importing…' : confirmLabel }}
                </Button>
            </div>
        </main>
    </div>
</template>
