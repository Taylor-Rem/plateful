<script setup lang="ts">
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Trash2 } from 'lucide-vue-next';

type CategoryOption = { id: number; name: string };

const props = defineProps<{
    open: boolean;
    item: App.Data.MenuItemData | null;
    categories: CategoryOption[];
    templates: App.Data.ItemTemplateData[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'delete-requested', item: App.Data.MenuItemData): void;
}>();

const isEdit = computed(() => props.item !== null);

const buildInitial = () => ({
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

const form = useForm(buildInitial());

const newImagePreview = ref<string | null>(null);
const currentImage = computed(() => props.item?.imageMediumUrl ?? null);

// Re-seed the form whenever the drawer (re)opens or the item changes.
watch(
    () => [props.open, props.item?.id] as const,
    ([isOpen]) => {
        if (isOpen) {
            const seed = buildInitial();
            Object.assign(form, seed);
            form.clearErrors();
            if (newImagePreview.value) {
                URL.revokeObjectURL(newImagePreview.value);
                newImagePreview.value = null;
            }
        }
    },
);

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

// Reset default selections when template changes after first load.
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

const formatDelta = (cents: number): string => {
    if (cents === 0) return '';
    const sign = cents > 0 ? '+' : '-';
    return `${sign}$${(Math.abs(cents) / 100).toFixed(2)}`;
};

const close = (): void => emit('update:open', false);

const submit = (): void => {
    if (isEdit.value && props.item) {
        form._method = 'put';
        form.post(`/admin/menu/items/${props.item.id}`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: close,
        });
    } else {
        form._method = 'post';
        form.post('/admin/menu/items', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: close,
        });
    }
};
</script>

<template>
    <Sheet :open="open" @update:open="(v) => emit('update:open', v)">
        <SheetContent class="w-full max-w-xl overflow-y-auto sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>{{ isEdit ? `Edit "${item?.name}"` : 'New menu item' }}</SheetTitle>
            </SheetHeader>

            <form class="space-y-5 px-4 py-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="se-item-name">Name</Label>
                    <Input id="se-item-name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="se-item-description">Description</Label>
                    <textarea
                        id="se-item-description"
                        v-model="form.description"
                        rows="3"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="se-item-category">Category</Label>
                        <select
                            id="se-item-category"
                            v-model="form.menu_category_id"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                            required
                        >
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                        <InputError :message="form.errors.menu_category_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="se-item-price">Price (USD)</Label>
                        <Input id="se-item-price" v-model="form.price" type="number" step="0.01" min="0" required />
                        <InputError :message="form.errors.price" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="se-item-template">Template</Label>
                    <select
                        id="se-item-template"
                        v-model="form.item_template_id"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                    >
                        <option :value="null">None (no configurator)</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <InputError :message="form.errors.item_template_id" />
                </div>

                <div v-if="selectedTemplate" class="rounded-md border border-border bg-muted/20 p-3">
                    <h4 class="text-sm font-medium text-foreground">Default selections</h4>
                    <InputError class="mt-2" :message="(form.errors as Record<string, string>)['default_selection_ids']" />
                    <div class="mt-3 space-y-3">
                        <div v-for="group in selectedTemplate.groups" :key="group.id">
                            <p class="text-xs font-medium text-foreground">{{ group.name }}</p>
                            <div v-if="group.isSingleSelect" class="mt-1 space-y-1">
                                <label v-if="!group.isRequired" class="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        :name="`se-grp-${group.id}`"
                                        :checked="!group.options.some((o) => isSelected(o.id))"
                                        @change="toggleSingle(group.id, null)"
                                    />
                                    None
                                </label>
                                <label v-for="opt in group.options" :key="opt.id" class="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        :name="`se-grp-${group.id}`"
                                        :checked="isSelected(opt.id)"
                                        @change="toggleSingle(group.id, opt.id)"
                                    />
                                    <span class="flex-1">{{ opt.name }}</span>
                                    <span class="text-xs text-muted-foreground">{{ formatDelta(opt.priceDeltaCents) }}</span>
                                </label>
                            </div>
                            <div v-else class="mt-1 grid gap-1 sm:grid-cols-2">
                                <label v-for="opt in group.options" :key="opt.id" class="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        :checked="isSelected(opt.id)"
                                        @change="toggleMulti(opt.id)"
                                    />
                                    <span class="flex-1">{{ opt.name }}</span>
                                    <span class="text-xs text-muted-foreground">{{ formatDelta(opt.priceDeltaCents) }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Image</Label>
                    <div class="flex items-start gap-3">
                        <div class="flex size-24 items-center justify-center overflow-hidden rounded-md border border-dashed border-border bg-muted/30">
                            <img v-if="newImagePreview" :src="newImagePreview" class="size-full object-cover" alt="" />
                            <img v-else-if="currentImage && !form.remove_image" :src="currentImage" class="size-full object-cover" alt="" />
                            <span v-else class="px-2 text-center text-xs text-muted-foreground">No image</span>
                        </div>
                        <div class="flex-1 space-y-2">
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                @change="onImageChange"
                            />
                            <button v-if="currentImage && !form.remove_image" type="button" class="text-xs text-destructive hover:opacity-80" @click="markRemoveImage">
                                Remove image
                            </button>
                            <p v-if="form.remove_image" class="text-xs text-amber-600">
                                Will remove on save.
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

                <SheetFooter class="flex-row items-center justify-between gap-2 pt-2">
                    <Button
                        v-if="isEdit && item"
                        type="button"
                        variant="ghost"
                        class="text-destructive hover:text-destructive"
                        @click="emit('delete-requested', item)"
                    >
                        <Trash2 class="size-4" /> Delete
                    </Button>
                    <span v-else />
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="outline" @click="close">Cancel</Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ isEdit ? 'Save changes' : 'Create item' }}
                        </Button>
                    </div>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
