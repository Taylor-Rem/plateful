<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    item: App.Data.MenuItemData;
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'addToCart', payload: { itemId: number; selections: Array<{ groupId: number; optionIds: number[] }>; unitPriceCents: number }): void;
}>();

const selectedIds = ref<number[]>([...props.item.defaultSelectionIds]);

// Reset when item changes or modal opens.
watch(
    () => [props.item.id, props.open] as const,
    ([_id, isOpen]) => {
        if (isOpen) {
            selectedIds.value = [...props.item.defaultSelectionIds];
        }
    },
);

const template = computed(() => props.item.template);

const isSelected = (optionId: number): boolean => selectedIds.value.includes(optionId);

const groupOptionIds = (groupId: number): number[] => {
    const g = template.value?.groups.find((gg) => gg.id === groupId);
    return g ? g.options.map((o) => o.id) : [];
};

const groupCount = (groupId: number): number => {
    const ids = groupOptionIds(groupId);
    return selectedIds.value.filter((id) => ids.includes(id)).length;
};

const groupSatisfied = (group: App.Data.ItemTemplateGroupData): boolean => {
    const count = groupCount(group.id);
    if (count < group.minSelections) return false;
    if (group.maxSelections !== null && count > group.maxSelections) return false;
    return true;
};

const allSatisfied = computed<boolean>(() => {
    if (!template.value) return true;
    return template.value.groups.every((g) => groupSatisfied(g));
});

const toggleSingle = (groupId: number, optionId: number | null): void => {
    const ids = groupOptionIds(groupId);
    selectedIds.value = selectedIds.value.filter((id) => !ids.includes(id));
    if (optionId !== null) {
        selectedIds.value.push(optionId);
    }
};

const toggleMulti = (groupId: number, optionId: number): void => {
    const ids = groupOptionIds(groupId);
    const group = template.value?.groups.find((g) => g.id === groupId);
    if (isSelected(optionId)) {
        selectedIds.value = selectedIds.value.filter((id) => id !== optionId);
        return;
    }
    if (group?.maxSelections != null) {
        const currentInGroup = selectedIds.value.filter((id) => ids.includes(id));
        if (currentInGroup.length >= group.maxSelections) {
            return;
        }
    }
    selectedIds.value = [...selectedIds.value, optionId];
};

const findOption = (optionId: number): App.Data.ItemTemplateOptionData | null => {
    if (!template.value) return null;
    for (const g of template.value.groups) {
        const o = g.options.find((opt) => opt.id === optionId);
        if (o) return o;
    }
    return null;
};

const unitPriceCents = computed<number>(() => {
    if (!template.value) return props.item.priceCents;
    const defaults = new Set(props.item.defaultSelectionIds);
    const current = new Set(selectedIds.value);

    let delta = 0;
    for (const id of current) {
        if (!defaults.has(id)) {
            const o = findOption(id);
            if (o) delta += o.priceDeltaCents;
        }
    }
    for (const id of defaults) {
        if (!current.has(id)) {
            const o = findOption(id);
            if (o) delta -= o.priceDeltaCents;
        }
    }
    return props.item.priceCents + delta;
});

const priceDiff = computed<number>(() => unitPriceCents.value - props.item.priceCents);

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;
const formatDelta = (cents: number): string => {
    if (cents === 0) return '';
    const sign = cents > 0 ? '+' : '-';
    return `${sign}$${(Math.abs(cents) / 100).toFixed(2)}`;
};

const close = (): void => emit('update:open', false);

const onSubmit = (): void => {
    if (!template.value || !allSatisfied.value) return;
    const selections = template.value.groups.map((g) => ({
        groupId: g.id,
        optionIds: selectedIds.value.filter((id) => g.options.some((o) => o.id === id)),
    }));
    emit('addToCart', {
        itemId: props.item.id,
        selections,
        unitPriceCents: unitPriceCents.value,
    });
    close();
};
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ item.name }}</DialogTitle>
            </DialogHeader>

            <div v-if="item.imageMediumUrl" class="-mx-6 -mt-2 aspect-[16/9] overflow-hidden bg-muted">
                <img :src="item.imageMediumUrl" :alt="item.name" class="size-full object-cover" />
            </div>

            <p v-if="item.description" class="text-sm text-muted-foreground">{{ item.description }}</p>

            <div v-if="template" class="space-y-4">
                <div
                    v-for="group in template.groups"
                    :key="group.id"
                    class="rounded-md border border-border bg-muted/20 p-3"
                >
                    <div class="flex items-baseline justify-between gap-2">
                        <h4 class="text-sm font-semibold text-foreground">
                            {{ group.name }}
                            <span v-if="group.isRequired" class="text-destructive">*</span>
                        </h4>
                        <span class="text-xs text-muted-foreground">
                            <template v-if="group.isSingleSelect">Pick exactly 1</template>
                            <template v-else-if="group.minSelections > 0 && group.maxSelections">
                                Pick {{ group.minSelections }}–{{ group.maxSelections }}
                            </template>
                            <template v-else-if="group.maxSelections">
                                Pick up to {{ group.maxSelections }}
                            </template>
                            <template v-else-if="group.minSelections > 0">
                                Pick at least {{ group.minSelections }}
                            </template>
                            <template v-else>Optional</template>
                            <span v-if="group.maxSelections"> ({{ groupCount(group.id) }} / {{ group.maxSelections }})</span>
                        </span>
                    </div>

                    <div v-if="group.isSingleSelect" class="mt-2 space-y-1.5">
                        <label
                            v-if="!group.isRequired"
                            class="flex items-center gap-2 text-sm text-foreground"
                        >
                            <input
                                type="radio"
                                :name="`grp-${group.id}`"
                                :checked="groupCount(group.id) === 0"
                                @change="toggleSingle(group.id, null)"
                            />
                            None
                        </label>
                        <label
                            v-for="opt in group.options"
                            :key="opt.id"
                            class="flex items-center gap-2 text-sm text-foreground"
                            :class="{ 'opacity-50': !opt.isAvailable }"
                        >
                            <input
                                type="radio"
                                :name="`grp-${group.id}`"
                                :checked="isSelected(opt.id)"
                                :disabled="!opt.isAvailable"
                                @change="toggleSingle(group.id, opt.id)"
                            />
                            <span class="flex-1">
                                {{ opt.name }}
                                <span v-if="!opt.isAvailable" class="ml-1 text-xs text-muted-foreground">(out of stock)</span>
                            </span>
                            <span class="text-xs text-muted-foreground">{{ formatDelta(opt.priceDeltaCents) }}</span>
                        </label>
                    </div>

                    <div v-else class="mt-2 grid gap-1.5">
                        <label
                            v-for="opt in group.options"
                            :key="opt.id"
                            class="flex items-center gap-2 text-sm text-foreground"
                            :class="{ 'opacity-50': !opt.isAvailable }"
                        >
                            <input
                                type="checkbox"
                                :checked="isSelected(opt.id)"
                                :disabled="!opt.isAvailable"
                                @change="toggleMulti(group.id, opt.id)"
                            />
                            <span class="flex-1">
                                {{ opt.name }}
                                <span v-if="!opt.isAvailable" class="ml-1 text-xs text-muted-foreground">(out of stock)</span>
                            </span>
                            <span class="text-xs text-muted-foreground">{{ formatDelta(opt.priceDeltaCents) }}</span>
                        </label>
                    </div>
                </div>
            </div>

            <DialogFooter class="flex items-center justify-between gap-2 sm:justify-between">
                <div class="text-left">
                    <div class="text-lg font-semibold text-foreground">{{ formatPrice(unitPriceCents) }}</div>
                    <div v-if="priceDiff !== 0" class="text-xs text-muted-foreground">
                        {{ formatDelta(priceDiff) }} vs base
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="button" variant="outline" @click="close">Cancel</Button>
                    <Button type="button" :disabled="!allSatisfied" @click="onSubmit">
                        Add to cart
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
