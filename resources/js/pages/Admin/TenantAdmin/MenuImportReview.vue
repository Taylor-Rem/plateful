<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import ImportCustomizationRows from '@/components/menu/ImportCustomizationRows.vue';
import type { DraftIngredientRow } from '@/components/menu/ImportCustomizationRows.vue';
import type { CustomizationSuggestion } from '@/components/menu/splitIngredients';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { relativeUrl } from '@/lib/relativeUrl';
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
    ingredients: DraftIngredientRow[];
    suggestions: CustomizationSuggestion[];
};

type DraftCategory = {
    name: string;
    items: DraftItem[];
    /** "Do this later": import the ingredients with every rule switched off. */
    skipCustomizations: boolean;
};

type ExistingCustomization = {
    name: string;
    ingredients: Array<{
        name: string;
        is_removable: boolean;
        extra_price_cents: number | null;
        swap_template_id: number | null;
    }>;
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
                ingredients?: string[];
                suggested_customizations?: CustomizationSuggestion[];
            }>;
        }>;
        optionSets: ImportOptionSet[];
        warnings: string[];
        itemCount: number;
        fileUrls: string[];
    };
    /** Ingredient rules on the current menu, keyed by lowercased item name. */
    existingCustomizations: Record<string, ExistingCustomization>;
    swapSets: App.Data.ItemTemplateData[];
}>();

// Rows for an item: what the owner already set up on the current menu wins
// (a re-import must not erase that work); otherwise the printed ingredients
// with the sensible default — customers may leave any of them out.
const seedIngredientRows = (
    name: string,
    printed: string[],
): DraftIngredientRow[] => {
    const kept = props.existingCustomizations[name.trim().toLowerCase()];

    if (kept) {
        return kept.ingredients.map((i) => ({
            name: i.name,
            is_removable: i.is_removable,
            extra_price:
                i.extra_price_cents === null
                    ? ''
                    : (i.extra_price_cents / 100).toFixed(2),
            swap_template_id: i.swap_template_id,
            swap_set: null,
            source: 'kept' as const,
        }));
    }

    return printed.map((n) => ({
        name: n,
        is_removable: true,
        extra_price: '',
        swap_template_id: null,
        swap_set: null,
        source: 'printed' as const,
    }));
};

const draft = reactive<{
    categories: DraftCategory[];
    optionSets: DraftOptionSet[];
}>({
    categories: props.menuImport.categories.map((category) => ({
        name: category.name,
        skipCustomizations: false,
        items: category.items.map((item) => ({
            name: item.name,
            description: item.description ?? '',
            price: (item.price_cents / 100).toFixed(2),
            price_note: item.price_note,
            option_set: item.option_set ?? null,
            ingredients: seedIngredientRows(item.name, item.ingredients ?? []),
            suggestions: [...(item.suggested_customizations ?? [])],
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

// Text inputs hold strings; `type="number"` inputs come back from v-model
// as numbers — accept both.
const priceCents = (price: string | number): number => {
    const parsed = Number.parseFloat(String(price).replace(/[$,\s]/g, ''));

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
};

const deltaCents = (price: string | number): number => priceCents(price);

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
        ingredients: [],
        suggestions: [],
    });
};

const removeItem = (category: DraftCategory, index: number): void => {
    category.items.splice(index, 1);
};

const addCategory = (): void => {
    draft.categories.push({
        name: '',
        skipCustomizations: false,
        items: [
            {
                name: '',
                description: '',
                price: '',
                price_note: null,
                option_set: null,
                ingredients: [],
                suggestions: [],
            },
        ],
    });
};

// ----- Step 2: customizations -----
const step = ref<'menu' | 'customize'>('menu');

const customizableCount = computed(() =>
    draft.categories.reduce(
        (sum, c) =>
            sum +
            c.items.filter(
                (i) => i.ingredients.length > 0 || i.suggestions.length > 0,
            ).length,
        0,
    ),
);

const keptCount = computed(() =>
    draft.categories.reduce(
        (sum, c) =>
            sum +
            c.items.filter((i) =>
                i.ingredients.some((r) => r.source === 'kept'),
            ).length,
        0,
    ),
);

// Current items whose customizations have no match in this import — the
// owner should know that work is about to be lost (usually a renamed item).
const lostCustomizations = computed(() => {
    const incoming = new Set(
        draft.categories.flatMap((c) =>
            c.items.map((i) => i.name.trim().toLowerCase()),
        ),
    );

    return Object.entries(props.existingCustomizations)
        .filter(([key]) => !incoming.has(key))
        .map(([, value]) => value.name);
});

const draftSwapSetNames = computed(() =>
    Array.from(
        new Set(
            draft.categories.flatMap((c) =>
                c.items.flatMap((i) =>
                    i.ingredients
                        .map((r) => r.swap_set?.name.trim() ?? '')
                        .filter((n) => n !== ''),
                ),
            ),
        ),
    ),
);

const dismissSuggestion = (item: DraftItem, index: number): void => {
    item.suggestions.splice(index, 1);
};

// Copy one item's rules onto every other item in the category that lists
// the same ingredient (by name). Nothing is added to items lacking it.
const applyToCategory = (category: DraftCategory, from: DraftItem): void => {
    for (const item of category.items) {
        if (item === from) {
            continue;
        }

        for (const rule of from.ingredients) {
            const target = item.ingredients.find(
                (r) =>
                    r.name.trim().toLowerCase() ===
                    rule.name.trim().toLowerCase(),
            );

            if (!target) {
                continue;
            }

            target.is_removable = rule.is_removable;
            target.extra_price = rule.extra_price;
            target.swap_template_id = rule.swap_template_id;
            target.swap_set = rule.swap_set
                ? {
                      name: rule.swap_set.name,
                      options: rule.swap_set.options.map((o) => ({ ...o })),
                  }
                : null;
        }
    }
};

type ConfirmIngredient = {
    name: string;
    is_removable: boolean;
    extra_price_cents: number | null;
    swap_template_id: number | null;
    swap_set: {
        name: string;
        options: Array<{ name: string; price_delta_cents: number }>;
    } | null;
};

const confirmIngredients = (
    item: DraftItem,
    skip: boolean,
): ConfirmIngredient[] =>
    item.ingredients
        .filter((r) => r.name.trim() !== '')
        .map((r) => ({
            name: r.name.trim(),
            is_removable: skip ? false : r.is_removable,
            extra_price_cents:
                skip || r.extra_price === '' ? null : priceCents(r.extra_price),
            swap_template_id: skip ? null : r.swap_template_id,
            swap_set:
                !skip && r.swap_set && r.swap_set.name.trim() !== ''
                    ? {
                          name: r.swap_set.name.trim(),
                          options: r.swap_set.options
                              .filter((o) => o.name.trim() !== '')
                              .map((o) => ({
                                  name: o.name.trim(),
                                  price_delta_cents: deltaCents(o.price_delta),
                              })),
                      }
                    : null,
        }))
        .map((r) => ({
            ...r,
            swap_set:
                r.swap_set && r.swap_set.options.length > 0 ? r.swap_set : null,
        }));

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
        ingredients: ConfirmIngredient[];
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
                    ingredients: confirmIngredients(
                        item,
                        category.skipCustomizations,
                    ),
                })),
        }))
        .filter(
            (category) =>
                category.name.trim() !== '' && category.items.length > 0,
        );

    confirmForm.post(
        relativeUrl(
            menuImportConfirm.url({
                restaurant: props.restaurant.subdomain,
                menuImport: props.menuImport.id,
            }),
        ),
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
        relativeUrl(
            menuImportDiscard.url({
                restaurant: props.restaurant.subdomain,
                menuImport: props.menuImport.id,
            }),
        ),
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
                        <h1 class="text-lg font-semibold">
                            {{
                                step === 'menu'
                                    ? 'Review your menu'
                                    : 'What can customers change?'
                            }}
                        </h1>
                        <p
                            class="hidden text-xs text-muted-foreground sm:block"
                        >
                            <template v-if="step === 'menu'">
                                Step 1 of 2 — check names and prices, fix
                                anything we misread.
                            </template>
                            <template v-else>
                                Step 2 of 2 — what customers can leave out, add
                                extra of, or swap. Skip any section for later.
                            </template>
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
                        v-if="step === 'menu'"
                        type="button"
                        :disabled="itemCount === 0 || missingPrices > 0"
                        data-test="next-step-button"
                        @click="step = 'customize'"
                    >
                        Next: customizations
                    </Button>
                    <template v-else>
                        <Button
                            type="button"
                            variant="outline"
                            @click="step = 'menu'"
                        >
                            Back
                        </Button>
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
                    </template>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
            <div v-show="step === 'menu'" class="space-y-6">
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
                    Past orders keep their history, but items customers still
                    have in open carts will be removed from those carts.
                </div>

                <div
                    v-if="missingPrices > 0"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    data-test="missing-prices-banner"
                >
                    <strong class="font-semibold"
                        >{{ missingPrices }}
                        {{ missingPrices === 1 ? 'item needs' : 'items need' }}
                        a price</strong
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
                        <li
                            v-for="(warning, i) in menuImport.warnings"
                            :key="i"
                        >
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
                            We read these choices and add-ons from your menu.
                            Assign them to items below — you can restructure
                            them anytime in the menu builder after importing.
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
                        :disabled="itemCount === 0 || missingPrices > 0"
                        @click="step = 'customize'"
                    >
                        Next: customizations
                    </Button>
                </div>
            </div>

            <div
                v-if="step === 'customize'"
                class="space-y-6"
                data-test="customize-step"
            >
                <div
                    class="rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground"
                >
                    We read the printed ingredients for
                    {{ customizableCount }}
                    {{ customizableCount === 1 ? 'item' : 'items' }}. By default
                    customers may leave any of them out; set a price to offer
                    extra, or pick a swap set to offer alternatives.
                    <strong class="text-foreground"
                        >Suggested by Plateful</strong
                    >
                    proposals stay off until you accept them.
                    <span v-if="keptCount > 0">
                        {{ keptCount }}
                        {{ keptCount === 1 ? 'item keeps' : 'items keep' }} the
                        rules already set on your current menu.
                    </span>
                </div>

                <div
                    v-if="lostCustomizations.length > 0"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    data-test="lost-customizations-banner"
                >
                    <strong class="font-semibold">
                        {{ lostCustomizations.length }}
                        {{
                            lostCustomizations.length === 1
                                ? 'item on your current menu has'
                                : 'items on your current menu have'
                        }}
                        customizations that won't carry over
                    </strong>
                    because no item in this import has the same name:
                    {{ lostCustomizations.join(', ') }}. Rename the matching
                    item on step 1 to keep them.
                </div>

                <p
                    v-if="errorMessages.length"
                    class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                >
                    {{ errorMessages.join(' ') }}
                </p>

                <section
                    v-for="(category, catIndex) in draft.categories"
                    :key="catIndex"
                    class="rounded-lg border border-border bg-card"
                    :data-test="`customize-category-${catIndex}`"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-border p-4"
                    >
                        <h2 class="font-semibold">{{ category.name }}</h2>
                        <label
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <input
                                v-model="category.skipCustomizations"
                                type="checkbox"
                            />
                            Skip for now (import ingredients with nothing
                            switched on)
                        </label>
                    </div>

                    <div class="divide-y divide-border">
                        <div
                            v-for="(item, itemIndex) in category.items"
                            :key="itemIndex"
                            class="space-y-2 p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p class="font-medium">{{ item.name }}</p>
                                <button
                                    v-if="
                                        item.ingredients.length > 0 &&
                                        category.items.length > 1
                                    "
                                    type="button"
                                    class="text-xs text-muted-foreground underline hover:text-foreground"
                                    :disabled="category.skipCustomizations"
                                    @click="applyToCategory(category, item)"
                                >
                                    Apply these rules to all in
                                    {{ category.name }}
                                </button>
                            </div>
                            <ImportCustomizationRows
                                v-model:rows="item.ingredients"
                                :suggestions="item.suggestions"
                                :swap-sets="swapSets"
                                :draft-swap-set-names="draftSwapSetNames"
                                :disabled="category.skipCustomizations"
                                @dismiss-suggestion="
                                    (i) => dismissSuggestion(item, i)
                                "
                                @accept-suggestion="
                                    (i) => dismissSuggestion(item, i)
                                "
                            />
                        </div>
                    </div>
                </section>

                <div
                    class="sticky bottom-0 -mx-1 flex justify-end gap-2 bg-background/80 py-3 backdrop-blur"
                >
                    <Button
                        type="button"
                        variant="outline"
                        @click="step = 'menu'"
                    >
                        Back
                    </Button>
                    <Button
                        type="button"
                        :disabled="
                            confirmForm.processing ||
                            itemCount === 0 ||
                            missingPrices > 0
                        "
                        @click="submit"
                    >
                        {{
                            confirmForm.processing ? 'Importing…' : confirmLabel
                        }}
                    </Button>
                </div>
            </div>
        </main>
    </div>
</template>
