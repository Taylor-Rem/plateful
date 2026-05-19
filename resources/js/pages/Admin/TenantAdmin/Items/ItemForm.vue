<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type CategoryOption = { id: number; name: string };

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: CategoryOption[];
    templates: App.Data.ItemTemplateData[];
    item: App.Data.MenuItemData | null;
}>();

const isEdit = computed(() => props.item !== null);
const base = computed(() => `/${props.restaurant.subdomain}`);

const form = useForm({
    _method: 'post' as 'post' | 'put',
    name: props.item?.name ?? '',
    description: props.item?.description ?? '',
    menu_category_id: props.item?.menuCategoryId ?? (props.categories[0]?.id ?? null),
    item_template_id: props.item?.itemTemplateId ?? null,
    price: props.item ? (props.item.priceCents / 100).toFixed(2) : '',
    is_available: props.item ? props.item.isAvailable : true,
    image: null as File | null,
    remove_image: false as boolean,
    default_selection_ids: [...(props.item?.defaultSelectionIds ?? [])] as number[],
});

const newImagePreview = ref<string | null>(null);
const currentImage = computed(() => props.item?.imageMediumUrl ?? null);

const onImageChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    form.image = file;
    form.remove_image = false;
    if (newImagePreview.value) {
        URL.revokeObjectURL(newImagePreview.value);
    }
    newImagePreview.value = file ? URL.createObjectURL(file) : null;
};

const markRemoveImage = (): void => {
    form.remove_image = true;
    form.image = null;
    if (newImagePreview.value) {
        URL.revokeObjectURL(newImagePreview.value);
        newImagePreview.value = null;
    }
};

const undoRemoveImage = (): void => {
    form.remove_image = false;
};

const selectedTemplate = computed<App.Data.ItemTemplateData | null>(() => {
    if (form.item_template_id === null) return null;
    return props.templates.find((t) => t.id === form.item_template_id) ?? null;
});

// Reset default selections when the template changes (but keep existing on first load).
let initialTemplateId: number | null = form.item_template_id;
watch(
    () => form.item_template_id,
    (val) => {
        if (val !== initialTemplateId) {
            form.default_selection_ids = [];
            initialTemplateId = val;
        }
    },
);

const isSelected = (optionId: number): boolean => form.default_selection_ids.includes(optionId);

const toggleSingle = (groupId: number, optionId: number | null): void => {
    const group = selectedTemplate.value?.groups.find((g) => g.id === groupId);
    if (!group) return;
    const groupOptionIds = group.options.map((o) => o.id);
    form.default_selection_ids = form.default_selection_ids.filter((id) => !groupOptionIds.includes(id));
    if (optionId !== null) {
        form.default_selection_ids.push(optionId);
    }
};

const toggleMulti = (optionId: number): void => {
    if (isSelected(optionId)) {
        form.default_selection_ids = form.default_selection_ids.filter((id) => id !== optionId);
    } else {
        form.default_selection_ids = [...form.default_selection_ids, optionId];
    }
};

const groupCount = (groupId: number): number => {
    const group = selectedTemplate.value?.groups.find((g) => g.id === groupId);
    if (!group) return 0;
    const ids = group.options.map((o) => o.id);
    return form.default_selection_ids.filter((id) => ids.includes(id)).length;
};

const groupSatisfied = (group: App.Data.ItemTemplateGroupData): boolean => {
    const count = groupCount(group.id);
    if (count < group.minSelections) return false;
    if (group.maxSelections !== null && count > group.maxSelections) return false;
    return true;
};

const defaultsValid = computed<boolean>(() => {
    const tpl = selectedTemplate.value;
    if (!tpl) return true;
    return tpl.groups.every((g) => groupSatisfied(g));
});

const formatDelta = (cents: number): string => {
    if (cents === 0) return '';
    const sign = cents > 0 ? '+' : '-';
    return `${sign}$${(Math.abs(cents) / 100).toFixed(2)}`;
};

const submit = (): void => {
    if (isEdit.value && props.item) {
        form._method = 'put';
        form.post(`${base.value}/menu/items/${props.item.id}`, {
            forceFormData: true,
            preserveScroll: true,
        });
    } else {
        form._method = 'post';
        form.post(`${base.value}/menu/items`, {
            forceFormData: true,
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <section class="rounded-lg border border-border bg-card p-5">
            <h3 class="text-base font-medium text-foreground">Details</h3>
            <div class="mt-4 grid gap-4">
                <div class="grid gap-2">
                    <Label for="item-name">Name</Label>
                    <Input id="item-name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="item-description">Description</Label>
                    <textarea
                        id="item-description"
                        v-model="form.description"
                        rows="3"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="item-category">Category</Label>
                        <select
                            id="item-category"
                            v-model="form.menu_category_id"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                            required
                        >
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                        <InputError :message="form.errors.menu_category_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="item-price">Price (USD)</Label>
                        <Input
                            id="item-price"
                            v-model="form.price"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                        />
                        <InputError :message="form.errors.price" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="item-template">Template</Label>
                    <select
                        id="item-template"
                        v-model="form.item_template_id"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                    >
                        <option :value="null">None (no configurator)</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Optional. If set, customers see a configurator with options from this template.
                    </p>
                    <InputError :message="form.errors.item_template_id" />
                </div>

                <div class="grid gap-2">
                    <Label>Image</Label>
                    <div class="flex items-start gap-4">
                        <div class="flex size-28 items-center justify-center overflow-hidden rounded-md border border-dashed border-border bg-muted/30">
                            <img
                                v-if="newImagePreview"
                                :src="newImagePreview"
                                alt="New image preview"
                                class="size-full object-cover"
                            />
                            <img
                                v-else-if="currentImage && !form.remove_image"
                                :src="currentImage"
                                alt="Current image"
                                class="size-full object-cover"
                            />
                            <span v-else class="px-2 text-center text-xs text-muted-foreground">No image</span>
                        </div>
                        <div class="flex-1 space-y-2">
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                @change="onImageChange"
                            />
                            <p class="text-xs text-muted-foreground">JPEG, PNG, or WebP up to 5 MB.</p>
                            <button
                                v-if="currentImage && !form.remove_image"
                                type="button"
                                class="text-xs text-destructive hover:opacity-80"
                                @click="markRemoveImage"
                            >
                                Remove image
                            </button>
                            <p v-if="form.remove_image" class="text-xs text-amber-600 dark:text-amber-400">
                                Will remove image on save.
                                <button type="button" class="underline" @click="undoRemoveImage">Undo</button>
                            </p>
                            <InputError :message="form.errors.image" />
                        </div>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm text-foreground">
                    <Checkbox v-model="form.is_available" />
                    Available on storefront
                </label>
            </div>
        </section>

        <section v-if="selectedTemplate" class="rounded-lg border border-border bg-card p-5">
            <h3 class="text-base font-medium text-foreground">Default selections</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                These options are pre-checked when customers open the configurator. The item's price should reflect this default configuration.
            </p>

            <InputError class="mt-2" :message="(form.errors as Record<string, string>)['default_selection_ids']" />

            <div class="mt-4 space-y-4">
                <div v-for="group in selectedTemplate.groups" :key="group.id" class="rounded-md border border-border bg-muted/20 p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h4 class="text-sm font-medium text-foreground">
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

                    <p v-if="!groupSatisfied(group)" class="mt-1 text-xs text-destructive">
                        Group "{{ group.name }}" is not satisfied.
                    </p>

                    <div v-if="group.isSingleSelect" class="mt-3 space-y-1.5">
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
                            <span class="flex-1">{{ opt.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ formatDelta(opt.priceDeltaCents) }}</span>
                        </label>
                    </div>

                    <div v-else class="mt-3 grid gap-1.5 sm:grid-cols-2">
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
                                @change="toggleMulti(opt.id)"
                            />
                            <span class="flex-1">{{ opt.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ formatDelta(opt.priceDeltaCents) }}</span>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <div class="flex items-center justify-end gap-2">
            <Button as-child variant="outline">
                <Link :href="`${base}/menu`">Cancel</Link>
            </Button>
            <Button type="submit" :disabled="form.processing || !defaultsValid">
                {{ isEdit ? 'Save changes' : 'Create item' }}
            </Button>
        </div>
    </form>
</template>
