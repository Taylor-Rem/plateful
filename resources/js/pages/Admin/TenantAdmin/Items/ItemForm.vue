<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type CategoryOption = { id: number; name: string };

type ModifierRow = {
    id: number | null;
    name: string;
    group_label: string;
    price_delta: string;
    is_default: boolean;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: CategoryOption[];
    item: App.Data.MenuItemData | null;
}>();

const isEdit = computed(() => props.item !== null);
const base = computed(() => `/${props.restaurant.subdomain}`);

const initialModifiers: ModifierRow[] = (props.item?.modifiers ?? []).map((m) => ({
    id: m.id,
    name: m.name,
    group_label: m.groupLabel ?? '',
    price_delta: (m.priceDeltaCents / 100).toFixed(2),
    is_default: m.isDefault,
}));

const form = useForm({
    _method: 'post' as 'post' | 'put',
    name: props.item?.name ?? '',
    description: props.item?.description ?? '',
    menu_category_id: props.item?.menuCategoryId ?? (props.categories[0]?.id ?? null),
    price: props.item ? (props.item.priceCents / 100).toFixed(2) : '',
    is_available: props.item ? props.item.isAvailable : true,
    image: null as File | null,
    remove_image: false as boolean,
    modifiers: initialModifiers,
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

const addModifier = (): void => {
    form.modifiers.push({
        id: null,
        name: '',
        group_label: '',
        price_delta: '0.00',
        is_default: false,
    });
};

const removeModifier = (index: number): void => {
    form.modifiers.splice(index, 1);
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

const modifierError = (index: number, field: string): string | undefined => {
    const key = `modifiers.${index}.${field}` as keyof typeof form.errors;
    return form.errors[key];
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <section class="rounded-lg border border-neutral-200 bg-white p-5">
            <h3 class="text-base font-medium text-neutral-900">Details</h3>
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
                        class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-400 focus:outline-none"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="item-category">Category</Label>
                        <select
                            id="item-category"
                            v-model="form.menu_category_id"
                            class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-400 focus:outline-none"
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
                    <Label>Image</Label>
                    <div class="flex items-start gap-4">
                        <div class="flex size-28 items-center justify-center overflow-hidden rounded-md border border-dashed border-neutral-300 bg-neutral-50">
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
                            <span v-else class="px-2 text-center text-xs text-neutral-400">No image</span>
                        </div>
                        <div class="flex-1 space-y-2">
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-md file:border-0 file:bg-neutral-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-neutral-800"
                                @change="onImageChange"
                            />
                            <p class="text-xs text-neutral-500">JPEG, PNG, or WebP up to 5 MB.</p>
                            <button
                                v-if="currentImage && !form.remove_image"
                                type="button"
                                class="text-xs text-red-600 hover:text-red-800"
                                @click="markRemoveImage"
                            >
                                Remove image
                            </button>
                            <p v-if="form.remove_image" class="text-xs text-amber-700">
                                Will remove image on save.
                                <button type="button" class="underline" @click="undoRemoveImage">Undo</button>
                            </p>
                            <InputError :message="form.errors.image" />
                        </div>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm text-neutral-800">
                    <Checkbox v-model="form.is_available" />
                    Available on storefront
                </label>
            </div>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-medium text-neutral-900">Modifiers</h3>
                <Button type="button" variant="outline" size="sm" @click="addModifier">
                    <Plus class="size-4" /> Add modifier
                </Button>
            </div>

            <p v-if="form.modifiers.length === 0" class="mt-4 text-sm text-neutral-500">
                No modifiers. Modifiers let customers choose size, toppings, etc.
            </p>

            <div v-for="(mod, index) in form.modifiers" :key="index" class="mt-4 grid gap-2 rounded-md border border-neutral-100 p-3 sm:grid-cols-12 sm:items-end">
                <div class="grid gap-1 sm:col-span-3">
                    <Label :for="`mod-name-${index}`">Name</Label>
                    <Input :id="`mod-name-${index}`" v-model="mod.name" required />
                    <InputError :message="modifierError(index, 'name')" />
                </div>
                <div class="grid gap-1 sm:col-span-3">
                    <Label :for="`mod-group-${index}`">Group</Label>
                    <Input :id="`mod-group-${index}`" v-model="mod.group_label" placeholder="e.g. Size" />
                    <InputError :message="modifierError(index, 'group_label')" />
                </div>
                <div class="grid gap-1 sm:col-span-3">
                    <Label :for="`mod-price-${index}`">Price delta</Label>
                    <Input
                        :id="`mod-price-${index}`"
                        v-model="mod.price_delta"
                        type="number"
                        step="0.01"
                        required
                    />
                    <InputError :message="modifierError(index, 'price_delta')" />
                </div>
                <div class="flex items-center gap-3 sm:col-span-3">
                    <label class="flex items-center gap-2 text-sm text-neutral-800">
                        <Checkbox v-model="mod.is_default" />
                        Default
                    </label>
                    <button
                        type="button"
                        class="ml-auto rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-red-600"
                        aria-label="Remove modifier"
                        @click="removeModifier(index)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
        </section>

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">{{ isEdit ? 'Save changes' : 'Create item' }}</Button>
            <Link
                :href="`${base}/menu`"
                class="rounded-md px-3 py-2 text-sm text-neutral-600 hover:text-neutral-900"
            >
                Cancel
            </Link>
        </div>
    </form>
</template>
