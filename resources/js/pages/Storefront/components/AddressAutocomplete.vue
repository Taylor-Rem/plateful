<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import {
    resolve,
    suggest,
} from '@/actions/App/Http/Controllers/Storefront/AddressLookupController';

export type AddressSnapshot = {
    street: string;
    street2: string;
    city: string;
    state: string;
    postal_code: string;
    country: string;
};

type Suggestion = {
    placeId: string;
    description: string;
    mainText: string;
    secondaryText: string;
};

const props = defineProps<{
    /** Text already in the field, e.g. a saved address being edited. */
    initialQuery?: string;
    invalid?: boolean;
}>();

const emit = defineEmits<{
    (e: 'resolved', address: AddressSnapshot): void;
    /** The typed text no longer corresponds to a resolved address. */
    (e: 'cleared'): void;
}>();

const query = ref(props.initialQuery ?? '');
const pendingPlaceId = ref('');
const suggestions = ref<Suggestion[]>([]);
const highlighted = ref(-1);
const open = ref(false);
const loading = ref(false);
const resolving = ref(false);
const error = ref<string | null>(null);

// One token per address the customer is picking. Google bills every keystroke
// plus the details lookup as a SINGLE session when they share a token — and
// bills them all separately when they don't. Rotated once a choice resolves,
// because that ends the session.
const sessionToken = ref<string>(crypto.randomUUID());

// useHttp carries the payload itself; the getters keep it in step with the refs.
const suggestHttp = useHttp(() => ({
    input: query.value,
    session_token: sessionToken.value,
}));

const resolveHttp = useHttp(() => ({
    place_id: pendingPlaceId.value,
    session_token: sessionToken.value,
}));

let debounce: ReturnType<typeof setTimeout> | undefined;
// Responses can land out of order; only the newest query's may be shown.
let latestQueryId = 0;

const clearSuggestions = (): void => {
    suggestions.value = [];
    highlighted.value = -1;
    open.value = false;
};

watch(query, (value) => {
    clearTimeout(debounce);
    error.value = null;

    // Typing after a pick means the resolved address no longer matches the
    // text, so the quote it produced must not survive.
    emit('cleared');

    if (value.trim().length < 3) {
        clearSuggestions();

        return;
    }

    debounce = setTimeout(() => void search(), 250);
});

const search = async (): Promise<void> => {
    const queryId = ++latestQueryId;
    loading.value = true;

    try {
        const { suggestions: results } = (await suggestHttp.submit(
            suggest(),
        )) as { suggestions: Suggestion[] };

        if (queryId !== latestQueryId) {
            return;
        }

        suggestions.value = results;
        highlighted.value = results.length > 0 ? 0 : -1;
        open.value = results.length > 0;
    } catch {
        if (queryId === latestQueryId) {
            clearSuggestions();
        }
    } finally {
        if (queryId === latestQueryId) {
            loading.value = false;
        }
    }
};

const choose = async (suggestion: Suggestion): Promise<void> => {
    query.value = suggestion.description;
    pendingPlaceId.value = suggestion.placeId;
    clearSuggestions();
    resolving.value = true;
    error.value = null;

    // Stop the watcher's debounced search from firing for the text we just set.
    clearTimeout(debounce);
    latestQueryId++;

    try {
        const { address } = (await resolveHttp.submit(resolve())) as {
            address: AddressSnapshot;
        };

        emit('resolved', address);
    } catch {
        error.value =
            'We couldn’t read a street address from that. Try picking another result.';
        emit('cleared');
    } finally {
        resolving.value = false;
        // The session ended with that lookup; the next address starts a new one.
        sessionToken.value = crypto.randomUUID();
    }
};

/**
 * Delayed so a click on a suggestion registers before the list disappears —
 * blur fires first.
 */
const closeSoon = (): void => {
    setTimeout(clearSuggestions, 120);
};

const onKeydown = (event: KeyboardEvent): void => {
    if (!open.value || suggestions.value.length === 0) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value = (highlighted.value + 1) % suggestions.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value =
            (highlighted.value - 1 + suggestions.value.length) %
            suggestions.value.length;
    } else if (event.key === 'Enter') {
        // Enter picks a suggestion rather than submitting the checkout form.
        event.preventDefault();
        const choice = suggestions.value[highlighted.value];

        if (choice) {
            void choose(choice);
        }
    } else if (event.key === 'Escape') {
        clearSuggestions();
    }
};

onBeforeUnmount(() => clearTimeout(debounce));

defineExpose({
    setQuery: (value: string) => {
        query.value = value;
    },
});
</script>

<template>
    <div class="relative">
        <label class="mb-1 block text-sm font-medium" for="address_search"
            >Street address</label
        >
        <input
            id="address_search"
            v-model="query"
            type="text"
            autocomplete="off"
            spellcheck="false"
            placeholder="Start typing your address…"
            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
            :class="invalid ? 'border-destructive' : 'border-input'"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open"
            @keydown="onKeydown"
            @blur="closeSoon"
        />

        <p v-if="resolving" class="mt-1 text-xs text-muted-foreground">
            Looking up that address…
        </p>
        <p v-else-if="loading" class="mt-1 text-xs text-muted-foreground">
            Searching…
        </p>
        <p v-if="error" class="mt-1 text-xs text-destructive">{{ error }}</p>

        <ul
            v-if="open"
            class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border border-border bg-card shadow-lg"
            role="listbox"
        >
            <li
                v-for="(s, i) in suggestions"
                :key="s.placeId"
                role="option"
                :aria-selected="i === highlighted"
                class="cursor-pointer px-3 py-2 text-sm"
                :class="i === highlighted ? 'bg-muted' : 'hover:bg-muted'"
                @mousedown.prevent="choose(s)"
                @mouseenter="highlighted = i"
            >
                <span class="block font-medium">{{ s.mainText }}</span>
                <span class="block text-xs text-muted-foreground">{{
                    s.secondaryText
                }}</span>
            </li>
        </ul>
    </div>
</template>
