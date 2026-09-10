<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { splitIngredients } from '@/components/menu/splitIngredients';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The owner-facing ingredient editor. One row per ingredient with the three
 * plain-language rules (leave out / extra $ / swap with), a "split from
 * description" starter, inline swap-set creation, and "apply to every item
 * in this category". Saves compile the item's option groups server-side.
 * Used by the storefront item drawer and the admin Menu page.
 */

type Row = {
    key: number;
    id: number | null;
    name: string;
    is_removable: boolean;
    extra_price: string;
    swap_template_id: number | null;
};

const props = withDefaults(
    defineProps<{
        item: App.Data.MenuItemData;
        /** Templates already filtered to swap-set shape. */
        swapSets: App.Data.ItemTemplateData[];
        categoryName: string;
        urls: { save: string; swapSet: string; applyToCategory: string };
        /** Seed rows from the description when the item has none yet. */
        autoSplit?: boolean;
    }>(),
    { autoSplit: false },
);

const emit = defineEmits<{
    (e: 'saved'): void;
    (e: 'preview'): void;
}>();

const page = usePage<{
    flash?: { success?: string | null; createdSwapSetId?: number | null };
}>();

let nextKey = 1;

const rowFromIngredient = (i: App.Data.MenuItemIngredientData): Row => ({
    key: nextKey++,
    id: i.id,
    name: i.name,
    is_removable: i.isRemovable,
    extra_price:
        i.extraPriceCents === null ? '' : (i.extraPriceCents / 100).toFixed(2),
    swap_template_id: i.swapTemplateId,
});

const blankRow = (name = ''): Row => ({
    key: nextKey++,
    id: null,
    name,
    is_removable: true,
    extra_price: '',
    swap_template_id: null,
});

const seedRows = (): Row[] => {
    if (props.item.ingredients.length > 0) {
        return props.item.ingredients.map(rowFromIngredient);
    }

    return props.autoSplit
        ? splitIngredients(props.item.description).map((n) => blankRow(n))
        : [];
};

const rows = ref<Row[]>(seedRows());
const dirty = ref(props.autoSplit && props.item.ingredients.length === 0);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

// After a save the page props carry the persisted rows (with ids); reseed
// so later edits update rather than recreate.
watch(
    () => props.item.ingredients,
    () => {
        if (!dirty.value) {
            rows.value = seedRows();
        }
    },
);

const markDirty = (): void => {
    dirty.value = true;
};

const addRow = (): void => {
    rows.value.push(blankRow());
    markDirty();
};

const removeRow = (index: number): void => {
    rows.value.splice(index, 1);
    markDirty();
};

const move = (index: number, delta: number): void => {
    const target = index + delta;

    if (target < 0 || target >= rows.value.length) {
        return;
    }

    const [row] = rows.value.splice(index, 1);
    rows.value.splice(target, 0, row);
    markDirty();
};

const splitFromDescription = (): void => {
    const names = splitIngredients(props.item.description);

    if (names.length === 0) {
        toast.error('Nothing to split — the description is empty.');

        return;
    }

    const existing = new Set(rows.value.map((r) => r.name.toLowerCase()));
    const added = names.filter((n) => !existing.has(n.toLowerCase()));

    rows.value.push(...added.map((n) => blankRow(n)));

    if (added.length > 0) {
        markDirty();
    }

    toast.success(
        added.length === 0
            ? 'Every ingredient in the description is already listed.'
            : `Added ${added.length} ingredient${added.length === 1 ? '' : 's'} from the description.`,
    );
};

const payload = (withIds: boolean) => ({
    ingredients: rows.value.map((r) => ({
        ...(withIds ? { id: r.id } : {}),
        name: r.name.trim(),
        is_removable: r.is_removable,
        extra_price: r.extra_price === '' ? null : r.extra_price,
        swap_template_id: r.swap_template_id,
    })),
});

const errorFor = (index: number, field: string): string | undefined =>
    errors.value[`ingredients.${index}.${field}`];

const save = (): void => {
    saving.value = true;
    errors.value = {};

    router.put(props.urls.save, payload(true), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            dirty.value = false;
            toast.success(page.props.flash?.success ?? 'Ingredients saved.');
            emit('saved');
        },
        onError: (e) => {
            errors.value = e as Record<string, string>;
            toast.error('Check the highlighted ingredients.');
        },
        onFinish: () => {
            saving.value = false;
        },
    });
};

// ----- Swap sets -----
const swapSetFormOpen = ref(false);
const swapSetForRowKey = ref<number | null>(null);
const swapSetSaving = ref(false);
const swapSetErrors = ref<Record<string, string>>({});
const swapSetForm = ref({
    name: '',
    allow_none: false,
    options: [
        { name: '', price_delta: '' },
        { name: '', price_delta: '' },
    ],
});

const openSwapSetForm = (row: Row): void => {
    swapSetForRowKey.value = row.key;
    swapSetErrors.value = {};
    swapSetForm.value = {
        name: '',
        allow_none: false,
        // The printed ingredient is the natural first option and default.
        options: [
            { name: row.name, price_delta: '' },
            { name: '', price_delta: '' },
        ],
    };
    swapSetFormOpen.value = true;
};

const onSwapSelect = (row: Row, event: Event): void => {
    const value = (event.target as HTMLSelectElement).value;

    if (value === '__new') {
        openSwapSetForm(row);

        return;
    }

    row.swap_template_id = value === '' ? null : Number(value);
    markDirty();
};

const addSwapOption = (): void => {
    swapSetForm.value.options.push({ name: '', price_delta: '' });
};

const removeSwapOption = (index: number): void => {
    swapSetForm.value.options.splice(index, 1);
};

const submitSwapSet = (): void => {
    swapSetSaving.value = true;
    swapSetErrors.value = {};

    const options = swapSetForm.value.options.filter(
        (o) => o.name.trim() !== '',
    );

    router.post(
        props.urls.swapSet,
        { ...swapSetForm.value, options },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                swapSetFormOpen.value = false;
                toast.success(page.props.flash?.success ?? 'Swap set created.');
            },
            onError: (e) => {
                swapSetErrors.value = e as Record<string, string>;
            },
            onFinish: () => {
                swapSetSaving.value = false;
            },
        },
    );
};

// The server flashes the new set's id; point the row that asked for it at
// the set once the templates prop carries it.
watch(
    () => page.props.flash?.createdSwapSetId,
    (id) => {
        if (!id || swapSetForRowKey.value === null) {
            return;
        }

        const row = rows.value.find((r) => r.key === swapSetForRowKey.value);

        if (row) {
            row.swap_template_id = id;
            markDirty();
        }

        swapSetForRowKey.value = null;
    },
);

// ----- Apply to category -----
const applyOpen = ref(false);
const applySelected = ref<Set<number>>(new Set());
const applying = ref(false);

const openApply = (): void => {
    applySelected.value = new Set(rows.value.map((r) => r.key));
    applyOpen.value = true;
};

const toggleApply = (key: number): void => {
    const next = new Set(applySelected.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    applySelected.value = next;
};

const swapSetName = (id: number | null): string =>
    props.swapSets.find((s) => s.id === id)?.name ?? '';

const ruleSummary = (row: Row): string => {
    const parts: string[] = [];
    parts.push(row.is_removable ? 'can leave out' : 'always included');

    if (row.extra_price !== '') {
        parts.push(`extra +$${Number(row.extra_price).toFixed(2)}`);
    }

    if (row.swap_template_id !== null) {
        parts.push(`swap with ${swapSetName(row.swap_template_id)}`);
    }

    return parts.join(' · ');
};

const submitApply = (): void => {
    applying.value = true;
    const selected = rows.value.filter((r) => applySelected.value.has(r.key));

    router.post(
        props.urls.applyToCategory,
        {
            ingredients: selected.map((r) => ({
                name: r.name.trim(),
                is_removable: r.is_removable,
                extra_price: r.extra_price === '' ? null : r.extra_price,
                swap_template_id: r.swap_template_id,
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                applyOpen.value = false;
                toast.success(page.props.flash?.success ?? 'Applied.');
                emit('saved');
            },
            onError: () => toast.error('Could not apply those rules.'),
            onFinish: () => {
                applying.value = false;
            },
        },
    );
};

const canApply = computed(
    () => !dirty.value && rows.value.length > 0 && !saving.value,
);
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-muted-foreground">
                What customers can leave out, add extra of, or swap.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="splitFromDescription"
                >
                    Split from description
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addRow"
                >
                    <Plus class="size-3.5" /> Add ingredient
                </Button>
            </div>
        </div>

        <p
            v-if="rows.length === 0"
            class="rounded-md border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground"
        >
            No ingredients yet. Split them from the description or add one.
        </p>

        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-muted-foreground">
                        <th class="pr-2 pb-1 font-medium">Ingredient</th>
                        <th class="pr-2 pb-1 font-medium whitespace-nowrap">
                            Can leave out
                        </th>
                        <th class="pr-2 pb-1 font-medium whitespace-nowrap">
                            Extra ($)
                        </th>
                        <th class="pr-2 pb-1 font-medium whitespace-nowrap">
                            Swap with
                        </th>
                        <th class="pb-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="row.key"
                        class="align-top"
                    >
                        <td class="py-1 pr-2">
                            <input
                                v-model="row.name"
                                type="text"
                                maxlength="120"
                                placeholder="e.g. Mortadella"
                                class="w-full min-w-32 rounded-md border border-input bg-background px-2 py-1.5 text-sm text-foreground"
                                :aria-label="`Ingredient ${index + 1} name`"
                                @input="markDirty"
                            />
                            <p
                                v-if="errorFor(index, 'name')"
                                class="mt-0.5 text-xs text-destructive"
                            >
                                {{ errorFor(index, 'name') }}
                            </p>
                        </td>
                        <td class="py-1 pr-2 text-center">
                            <input
                                v-model="row.is_removable"
                                type="checkbox"
                                class="mt-2"
                                :aria-label="`${row.name || 'Ingredient'} can be left out`"
                                @change="markDirty"
                            />
                        </td>
                        <td class="py-1 pr-2">
                            <input
                                v-model="row.extra_price"
                                type="number"
                                step="0.01"
                                min="0"
                                max="999.99"
                                placeholder="—"
                                class="w-20 rounded-md border border-input bg-background px-2 py-1.5 text-sm text-foreground"
                                :aria-label="`Extra ${row.name || 'ingredient'} price`"
                                @input="markDirty"
                            />
                            <p
                                v-if="errorFor(index, 'extra_price')"
                                class="mt-0.5 text-xs text-destructive"
                            >
                                {{ errorFor(index, 'extra_price') }}
                            </p>
                        </td>
                        <td class="py-1 pr-2">
                            <select
                                :value="row.swap_template_id ?? ''"
                                class="w-full min-w-28 rounded-md border border-input bg-background px-2 py-1.5 text-sm text-foreground"
                                :aria-label="`Swap ${row.name || 'ingredient'} with`"
                                @change="onSwapSelect(row, $event)"
                            >
                                <option value="">No swap</option>
                                <option
                                    v-for="set in swapSets"
                                    :key="set.id"
                                    :value="set.id"
                                >
                                    {{ set.name }}
                                </option>
                                <option value="__new">+ New swap set…</option>
                            </select>
                            <p
                                v-if="errorFor(index, 'swap_template_id')"
                                class="mt-0.5 text-xs text-destructive"
                            >
                                {{ errorFor(index, 'swap_template_id') }}
                            </p>
                        </td>
                        <td class="py-1">
                            <div class="flex items-center">
                                <button
                                    type="button"
                                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-30"
                                    :disabled="index === 0"
                                    aria-label="Move up"
                                    @click="move(index, -1)"
                                >
                                    <ArrowUp class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-30"
                                    :disabled="index === rows.length - 1"
                                    aria-label="Move down"
                                    @click="move(index, 1)"
                                >
                                    <ArrowDown class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                    :aria-label="`Remove ${row.name || 'ingredient'}`"
                                    @click="removeRow(index)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="errors.ingredients" class="text-xs text-destructive">
            {{ errors.ingredients }}
        </p>

        <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    :disabled="saving || !dirty"
                    @click="save"
                >
                    {{ saving ? 'Saving…' : 'Save ingredients' }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="item.groups.length === 0"
                    title="See exactly what customers see"
                    @click="emit('preview')"
                >
                    Preview as customer
                </Button>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                :disabled="!canApply"
                :title="
                    dirty
                        ? 'Save ingredients first'
                        : `Apply these rules to every item in ${categoryName}`
                "
                @click="openApply"
            >
                Apply to all in {{ categoryName }}…
            </Button>
        </div>

        <Dialog v-model:open="swapSetFormOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New swap set</DialogTitle>
                    <DialogDescription>
                        A pick-one list customers choose from, reusable across
                        the menu — "Cheeses", "Breads", "Sauces". The first
                        option is what this item comes with.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submitSwapSet">
                    <div class="grid gap-1">
                        <label
                            for="swap-set-name"
                            class="text-sm font-medium text-foreground"
                            >Name</label
                        >
                        <input
                            id="swap-set-name"
                            v-model="swapSetForm.name"
                            type="text"
                            maxlength="80"
                            required
                            placeholder="e.g. Cheeses"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground"
                        />
                        <p
                            v-if="swapSetErrors.name"
                            class="text-xs text-destructive"
                        >
                            {{ swapSetErrors.name }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <p class="text-sm font-medium text-foreground">
                            Options
                        </p>
                        <div
                            v-for="(opt, i) in swapSetForm.options"
                            :key="i"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="opt.name"
                                type="text"
                                maxlength="120"
                                :placeholder="
                                    i === 0 ? 'Comes with' : 'Alternative'
                                "
                                class="flex-1 rounded-md border border-input bg-background px-2 py-1.5 text-sm text-foreground"
                                :aria-label="`Option ${i + 1} name`"
                            />
                            <input
                                v-model="opt.price_delta"
                                type="number"
                                step="0.01"
                                placeholder="+$"
                                class="w-20 rounded-md border border-input bg-background px-2 py-1.5 text-sm text-foreground"
                                :aria-label="`Option ${i + 1} price difference`"
                            />
                            <button
                                type="button"
                                class="rounded p-1 text-muted-foreground hover:text-destructive disabled:opacity-30"
                                :disabled="swapSetForm.options.length <= 1"
                                aria-label="Remove option"
                                @click="removeSwapOption(i)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                        <p
                            v-if="swapSetErrors.options"
                            class="text-xs text-destructive"
                        >
                            {{ swapSetErrors.options }}
                        </p>
                        <button
                            type="button"
                            class="text-xs text-muted-foreground underline hover:text-foreground"
                            @click="addSwapOption"
                        >
                            + Another option
                        </button>
                    </div>
                    <label
                        class="flex items-center gap-2 text-sm text-foreground"
                    >
                        <input
                            v-model="swapSetForm.allow_none"
                            type="checkbox"
                        />
                        Customers may also pick none
                    </label>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="swapSetFormOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="swapSetSaving">
                            Create set
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="applyOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle
                        >Apply to all in {{ categoryName }}</DialogTitle
                    >
                    <DialogDescription>
                        Every item in this category that lists one of these
                        ingredients gets the same rule. Items without it are
                        left alone — nothing is added.
                    </DialogDescription>
                </DialogHeader>
                <ul class="max-h-72 space-y-1.5 overflow-y-auto">
                    <li v-for="row in rows" :key="row.key">
                        <label
                            class="flex items-start gap-2 text-sm text-foreground"
                        >
                            <input
                                type="checkbox"
                                class="mt-1"
                                :checked="applySelected.has(row.key)"
                                @change="toggleApply(row.key)"
                            />
                            <span>
                                <span class="font-medium">{{ row.name }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ ruleSummary(row) }}
                                </span>
                            </span>
                        </label>
                    </li>
                </ul>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="applyOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="button"
                        :disabled="applying || applySelected.size === 0"
                        @click="submitApply"
                    >
                        Apply
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
