<script setup lang="ts">
import { Trash2 } from 'lucide-vue-next';
import {
    acceptSimpleSuggestion,
    rowForSwapSuggestion,
} from '@/components/menu/splitIngredients';
import type { CustomizationSuggestion } from '@/components/menu/splitIngredients';
import { Button } from '@/components/ui/button';

/**
 * One item's ingredient rules inside the import wizard. Works on the review
 * draft (nothing is saved until the import is confirmed), so a swap is
 * defined inline here — the builder creates the set on confirm and shares
 * it across the import by name.
 */

export type DraftSwapSet = {
    name: string;
    options: Array<{ name: string; price_delta: string }>;
};

export type DraftIngredientRow = {
    name: string;
    is_removable: boolean;
    allow_half: boolean;
    /** Price of the Double level; blank means Double isn't offered. */
    extra_price: string;
    /** An existing swap set (carried over from the current menu). */
    swap_template_id: number | null;
    /** A swap set defined in this import. */
    swap_set: DraftSwapSet | null;
    /** Where the row came from — shown as a hint. */
    source: 'printed' | 'kept' | 'added';
};

const rows = defineModel<DraftIngredientRow[]>('rows', { required: true });

const props = defineProps<{
    suggestions: CustomizationSuggestion[];
    swapSets: App.Data.ItemTemplateData[];
    /** Swap sets other rows in this import have defined, by name. */
    draftSwapSetNames: string[];
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'changed'): void;
    (e: 'dismiss-suggestion', index: number): void;
    (e: 'accept-suggestion', index: number): void;
}>();

const makeRow = (name: string): DraftIngredientRow => ({
    name,
    is_removable: true,
    allow_half: true,
    extra_price: '',
    swap_template_id: null,
    swap_set: null,
    source: 'added',
});

const addRow = (): void => {
    rows.value.push(makeRow(''));
    emit('changed');
};

const removeRow = (index: number): void => {
    rows.value.splice(index, 1);
    emit('changed');
};

const swapValue = (row: DraftIngredientRow): string => {
    if (row.swap_set) {
        return `draft:${row.swap_set.name}`;
    }

    return row.swap_template_id === null ? '' : `id:${row.swap_template_id}`;
};

const onSwapSelect = (row: DraftIngredientRow, event: Event): void => {
    const value = (event.target as HTMLSelectElement).value;

    if (value === '') {
        row.swap_set = null;
        row.swap_template_id = null;
    } else if (value === '__new') {
        row.swap_template_id = null;
        row.swap_set = {
            name: row.name || 'Swap',
            options: [
                { name: row.name, price_delta: '' },
                { name: '', price_delta: '' },
            ],
        };
    } else if (value.startsWith('id:')) {
        row.swap_set = null;
        row.swap_template_id = Number(value.slice(3));
    } else if (value.startsWith('draft:')) {
        // Reuse a set another row defined: same name, builder merges by name.
        const name = value.slice(6);
        row.swap_template_id = null;
        row.swap_set = {
            name,
            options: [{ name: row.name, price_delta: '' }],
        };
    }

    emit('changed');
};

const addSwapOption = (row: DraftIngredientRow): void => {
    row.swap_set?.options.push({ name: '', price_delta: '' });
};

const removeSwapOption = (row: DraftIngredientRow, index: number): void => {
    row.swap_set?.options.splice(index, 1);
};

/**
 * Accept a proposal: extras and leave-outs add or adjust a row; a swap adds
 * the row with an inline set pre-filled from the proposed alternatives.
 */
const accept = (index: number): void => {
    const suggestion = props.suggestions[index];

    if (suggestion.kind === 'swap') {
        const row = rowForSwapSuggestion(rows.value, suggestion, makeRow);

        row.swap_template_id = null;
        row.swap_set = {
            name: suggestion.name,
            options: suggestion.swap_options.map((name) => ({
                name,
                price_delta: '',
            })),
        };
    } else {
        acceptSimpleSuggestion(rows.value, suggestion, makeRow);
    }

    emit('accept-suggestion', index);
    emit('changed');
};

const kindLabel = (kind: CustomizationSuggestion['kind']): string =>
    kind === 'extra' ? 'Extra' : kind === 'swap' ? 'Swap' : 'Leave out';
</script>

<template>
    <div class="space-y-2" :class="{ 'opacity-50': disabled }">
        <p v-if="rows.length === 0" class="text-xs text-muted-foreground">
            No ingredients read for this item.
        </p>
        <table v-else class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-muted-foreground">
                    <th class="pr-2 pb-1 font-medium">Ingredient</th>
                    <th class="pr-2 pb-1 font-medium whitespace-nowrap">
                        None
                    </th>
                    <th class="pr-2 pb-1 font-medium whitespace-nowrap">
                        Half
                    </th>
                    <th class="pr-2 pb-1 font-medium whitespace-nowrap">
                        Double ($)
                    </th>
                    <th class="pr-2 pb-1 font-medium whitespace-nowrap">
                        Swap with
                    </th>
                    <th class="pb-1"></th>
                </tr>
            </thead>
            <tbody>
                <template v-for="(row, index) in rows" :key="index">
                    <tr class="align-top">
                        <td class="py-1 pr-2">
                            <input
                                v-model="row.name"
                                type="text"
                                maxlength="120"
                                :disabled="disabled"
                                class="w-full min-w-28 rounded-md border border-input bg-background px-2 py-1.5 text-sm text-foreground"
                                :aria-label="`Ingredient ${index + 1} name`"
                                @input="emit('changed')"
                            />
                            <span
                                v-if="row.source === 'kept'"
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                                >kept from current menu</span
                            >
                        </td>
                        <td class="py-1 pr-2 text-center">
                            <input
                                v-model="row.is_removable"
                                type="checkbox"
                                class="mt-2"
                                :disabled="disabled"
                                :aria-label="`${row.name || 'Ingredient'} can be left out`"
                                @change="emit('changed')"
                            />
                        </td>
                        <td class="py-1 pr-2 text-center">
                            <input
                                v-model="row.allow_half"
                                type="checkbox"
                                class="mt-2"
                                :disabled="disabled"
                                :aria-label="`${row.name || 'Ingredient'} half portion`"
                                @change="emit('changed')"
                            />
                        </td>
                        <td class="py-1 pr-2">
                            <input
                                v-model="row.extra_price"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="—"
                                :disabled="disabled"
                                class="w-20 rounded-md border border-input bg-background px-2 py-1.5 text-sm text-foreground"
                                :aria-label="`Double ${row.name || 'ingredient'} price`"
                                @input="emit('changed')"
                            />
                        </td>
                        <td class="py-1 pr-2">
                            <select
                                :value="swapValue(row)"
                                :disabled="disabled"
                                class="w-full min-w-28 rounded-md border border-input bg-background px-2 py-1.5 text-sm text-foreground"
                                :aria-label="`Swap ${row.name || 'ingredient'} with`"
                                @change="onSwapSelect(row, $event)"
                            >
                                <option value="">No swap</option>
                                <option
                                    v-for="set in swapSets"
                                    :key="set.id"
                                    :value="`id:${set.id}`"
                                >
                                    {{ set.name }}
                                </option>
                                <option
                                    v-for="name in draftSwapSetNames.filter(
                                        (n) => n !== row.swap_set?.name,
                                    )"
                                    :key="name"
                                    :value="`draft:${name}`"
                                >
                                    {{ name }} (this import)
                                </option>
                                <option
                                    v-if="row.swap_set"
                                    :value="`draft:${row.swap_set.name}`"
                                >
                                    {{ row.swap_set.name }} (this import)
                                </option>
                                <option value="__new">+ New swap set…</option>
                            </select>
                        </td>
                        <td class="py-1">
                            <button
                                type="button"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                :disabled="disabled"
                                :aria-label="`Remove ${row.name || 'ingredient'}`"
                                @click="removeRow(index)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="row.swap_set" class="align-top">
                        <td colspan="5" class="pb-2">
                            <div
                                class="ml-4 space-y-1 rounded-md border border-border bg-muted/20 p-2"
                            >
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="text-muted-foreground"
                                        >Swap set</span
                                    >
                                    <input
                                        v-model="row.swap_set.name"
                                        type="text"
                                        maxlength="80"
                                        :disabled="disabled"
                                        class="rounded-md border border-input bg-background px-2 py-1 text-xs text-foreground"
                                        aria-label="Swap set name"
                                        @input="emit('changed')"
                                    />
                                    <span class="text-muted-foreground"
                                        >first option is what it comes
                                        with</span
                                    >
                                </div>
                                <div
                                    v-for="(opt, i) in row.swap_set.options"
                                    :key="i"
                                    class="flex items-center gap-2"
                                >
                                    <input
                                        v-model="opt.name"
                                        type="text"
                                        maxlength="120"
                                        :disabled="disabled"
                                        class="flex-1 rounded-md border border-input bg-background px-2 py-1 text-xs text-foreground"
                                        :aria-label="`Swap option ${i + 1} name`"
                                        @input="emit('changed')"
                                    />
                                    <input
                                        v-model="opt.price_delta"
                                        type="number"
                                        step="0.01"
                                        placeholder="+$"
                                        :disabled="disabled"
                                        class="w-20 rounded-md border border-input bg-background px-2 py-1 text-xs text-foreground"
                                        :aria-label="`Swap option ${i + 1} price difference`"
                                        @input="emit('changed')"
                                    />
                                    <button
                                        type="button"
                                        class="rounded p-1 text-muted-foreground hover:text-destructive disabled:opacity-30"
                                        :disabled="
                                            disabled ||
                                            row.swap_set.options.length <= 1
                                        "
                                        aria-label="Remove swap option"
                                        @click="removeSwapOption(row, i)"
                                    >
                                        <Trash2 class="size-3" />
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    class="text-xs text-muted-foreground underline hover:text-foreground"
                                    :disabled="disabled"
                                    @click="addSwapOption(row)"
                                >
                                    + Another option
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                class="text-xs text-muted-foreground underline hover:text-foreground"
                :disabled="disabled"
                @click="addRow"
            >
                + Add ingredient
            </button>
        </div>

        <div
            v-if="suggestions.length > 0"
            class="space-y-1 rounded-md border border-dashed border-border p-2"
            data-test="import-suggestions"
        >
            <p class="text-xs font-medium text-foreground">
                Suggested by Plateful — off until you accept them
            </p>
            <ul class="space-y-1">
                <li
                    v-for="(suggestion, index) in suggestions"
                    :key="`${suggestion.kind}-${suggestion.name}`"
                    class="flex flex-wrap items-center justify-between gap-2 text-sm"
                >
                    <span class="min-w-0">
                        <span
                            class="mr-1.5 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-primary uppercase"
                            >{{ kindLabel(suggestion.kind) }}</span
                        >
                        <span class="font-medium text-foreground">{{
                            suggestion.name
                        }}</span>
                        <span
                            v-if="suggestion.kind === 'swap'"
                            class="text-muted-foreground"
                        >
                            — {{ suggestion.swap_options.join(' / ') }}</span
                        >
                        <span
                            v-if="suggestion.reason"
                            class="block text-xs text-muted-foreground"
                            >{{ suggestion.reason }}</span
                        >
                    </span>
                    <span class="flex shrink-0 items-center gap-1">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="disabled"
                            @click="accept(index)"
                            >Accept</Button
                        >
                        <button
                            type="button"
                            class="rounded p-1 text-muted-foreground hover:text-foreground"
                            :aria-label="`Dismiss ${suggestion.name}`"
                            @click="emit('dismiss-suggestion', index)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
