<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Check, ExternalLink, UtensilsCrossed } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    menuPresets: { value: string; label: string }[];
    menuSummary: { categories: number; items: number };
}>();

const emit = defineEmits<{ advance: [] }>();

const presetForm = useForm<{ preset: string }>({ preset: '' });
const applying = ref<string | null>(null);

const applyPreset = (preset: string): void => {
    applying.value = preset;
    presetForm.preset = preset;
    presetForm.post(`/${props.restaurant.subdomain}/onboarding/menu-preset`, {
        preserveScroll: true,
        onFinish: () => {
            applying.value = null;
        },
    });
};
</script>

<template>
    <div class="space-y-4">
        <template v-if="menuSummary.items > 0">
            <div
                class="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950"
                data-test="menu-step-complete"
            >
                <span
                    class="mt-0.5 flex size-6 items-center justify-center rounded-full bg-green-100 text-green-700"
                >
                    <Check class="size-4" />
                </span>
                <div>
                    <p class="text-sm font-medium">
                        Your menu has {{ menuSummary.items }}
                        {{ menuSummary.items === 1 ? 'item' : 'items' }} in
                        {{ menuSummary.categories }}
                        {{ menuSummary.categories === 1 ? 'category' : 'categories' }}.
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Rename, re-price, or reorganize anytime in the menu
                        builder — it's your menu now.
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <a
                    :href="`/${restaurant.subdomain}/menu`"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-80"
                >
                    Open the menu builder
                    <ExternalLink class="size-3.5" />
                </a>
                <Button type="button" data-test="menu-continue-button" @click="emit('advance')">
                    Continue
                </Button>
            </div>
        </template>

        <template v-else>
            <p class="text-sm text-muted-foreground">
                Start from a template — we'll build a full menu with categories
                and prices that you can edit item by item. Or build your own
                from scratch.
            </p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <button
                    v-for="preset in menuPresets"
                    :key="preset.value"
                    type="button"
                    class="flex flex-col items-center gap-2 rounded-lg border border-border bg-card p-4 text-sm font-medium transition hover:border-primary hover:bg-primary/5 disabled:opacity-50"
                    :disabled="presetForm.processing"
                    :data-test="`menu-preset-${preset.value}`"
                    @click="applyPreset(preset.value)"
                >
                    <UtensilsCrossed class="size-5 text-muted-foreground" />
                    {{ applying === preset.value ? 'Adding…' : preset.label }}
                </button>
            </div>
            <p v-if="presetForm.errors.preset" class="text-sm text-destructive">
                {{ presetForm.errors.preset }}
            </p>

            <p class="text-sm text-muted-foreground">
                Prefer to start blank?
                <a
                    :href="`/${restaurant.subdomain}/menu`"
                    class="font-medium text-primary underline hover:opacity-80"
                >
                    Build your menu from scratch
                </a>
                — you need at least one item before going live.
            </p>
        </template>
    </div>
</template>
