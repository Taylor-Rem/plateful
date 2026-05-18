<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { GripVertical, Pencil, Trash2, Plus } from 'lucide-vue-next';
import { VueDraggable } from 'vue-draggable-plus';
import { computed, ref } from 'vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: App.Data.MenuCategoryData[];
}>();

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

const base = computed(() => `/${props.restaurant.subdomain}`);

const localCategories = ref<App.Data.MenuCategoryData[]>([...props.categories]);

// Keep local state in sync if the server re-renders with new data
const refreshLocal = (): void => {
    localCategories.value = [...props.categories];
};

const onCategoryDragEnd = (): void => {
    const ids = localCategories.value.map((c) => c.id);
    router.post(
        `${base.value}/menu/categories/reorder`,
        { ids },
        { preserveScroll: true, preserveState: true, onSuccess: refreshLocal },
    );
};

const onItemDragEnd = (category: App.Data.MenuCategoryData): void => {
    const ids = category.items.map((i) => i.id);
    router.post(
        `${base.value}/menu/items/reorder`,
        { category_id: category.id, ids },
        { preserveScroll: true, preserveState: true, onSuccess: refreshLocal },
    );
};

// Category form modal state
const showCategoryModal = ref(false);
const editingCategory = ref<App.Data.MenuCategoryData | null>(null);

const categoryForm = useForm({
    name: '',
    description: '' as string | null,
});

const openCreateCategory = (): void => {
    editingCategory.value = null;
    categoryForm.reset();
    categoryForm.clearErrors();
    showCategoryModal.value = true;
};

const openEditCategory = (category: App.Data.MenuCategoryData): void => {
    editingCategory.value = category;
    categoryForm.name = category.name;
    categoryForm.description = category.description ?? '';
    categoryForm.clearErrors();
    showCategoryModal.value = true;
};

const submitCategory = (): void => {
    if (editingCategory.value) {
        categoryForm.put(`${base.value}/menu/categories/${editingCategory.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showCategoryModal.value = false;
            },
        });
    } else {
        categoryForm.post(`${base.value}/menu/categories`, {
            preserveScroll: true,
            onSuccess: () => {
                showCategoryModal.value = false;
                categoryForm.reset();
            },
        });
    }
};

const deleteCategory = (category: App.Data.MenuCategoryData): void => {
    if (category.items.length > 0) {
        return;
    }
    if (!confirm(`Delete category "${category.name}"?`)) {
        return;
    }
    router.delete(`${base.value}/menu/categories/${category.id}`, { preserveScroll: true });
};

const deleteItem = (item: App.Data.MenuItemData): void => {
    if (!confirm(`Delete item "${item.name}"?`)) {
        return;
    }
    router.delete(`${base.value}/menu/items/${item.id}`, { preserveScroll: true });
};
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Menu`" />

        <div class="flex items-center justify-between">
            <h2 class="text-2xl font-semibold text-neutral-900">Menu</h2>
            <div class="flex items-center gap-2">
                <Link
                    :href="`${base}/menu/items/create`"
                    class="inline-flex items-center gap-1 rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-800"
                >
                    <Plus class="size-4" /> Add item
                </Link>
                <Button variant="outline" @click="openCreateCategory">
                    <Plus class="size-4" /> Add category
                </Button>
            </div>
        </div>

        <div v-if="localCategories.length === 0" class="mt-12 rounded-lg border border-dashed border-neutral-300 bg-white p-10 text-center">
            <h3 class="text-base font-medium text-neutral-900">No categories yet</h3>
            <p class="mt-1 text-sm text-neutral-500">Create your first category to start building the menu.</p>
            <Button class="mt-4" @click="openCreateCategory">
                <Plus class="size-4" /> Add category
            </Button>
        </div>

        <VueDraggable
            v-else
            v-model="localCategories"
            :animation="150"
            handle=".category-handle"
            class="mt-6 space-y-6"
            @end="onCategoryDragEnd"
        >
            <section
                v-for="category in localCategories"
                :key="category.id"
                class="rounded-lg border border-neutral-200 bg-white"
            >
                <header class="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <button class="category-handle cursor-grab text-neutral-400 hover:text-neutral-600" type="button" aria-label="Drag category">
                            <GripVertical class="size-4" />
                        </button>
                        <h3 class="text-lg font-medium text-neutral-900">{{ category.name }}</h3>
                        <span class="text-xs text-neutral-500">{{ category.items.length }} item<span v-if="category.items.length !== 1">s</span></span>
                    </div>
                    <div class="flex items-center gap-1">
                        <button
                            class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
                            type="button"
                            aria-label="Edit category"
                            @click="openEditCategory(category)"
                        >
                            <Pencil class="size-4" />
                        </button>
                        <button
                            class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40"
                            type="button"
                            aria-label="Delete category"
                            :title="category.items.length > 0 ? 'Move or delete items first' : 'Delete category'"
                            :disabled="category.items.length > 0"
                            @click="deleteCategory(category)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </header>

                <div v-if="category.items.length === 0" class="px-4 py-6 text-center text-sm text-neutral-500">
                    No items yet.
                </div>

                <VueDraggable
                    v-else
                    v-model="category.items"
                    :animation="150"
                    handle=".item-handle"
                    class="divide-y divide-neutral-100"
                    @end="onItemDragEnd(category)"
                >
                    <div
                        v-for="item in category.items"
                        :key="item.id"
                        class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <button class="item-handle cursor-grab text-neutral-400 hover:text-neutral-600" type="button" aria-label="Drag item">
                                <GripVertical class="size-4" />
                            </button>
                            <img
                                v-if="item.imageThumbUrl"
                                :src="item.imageThumbUrl"
                                :alt="item.name"
                                class="size-8 shrink-0 rounded object-cover"
                            />
                            <span class="truncate text-neutral-900">{{ item.name }}</span>
                            <span
                                v-if="!item.isAvailable"
                                class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600"
                            >Unavailable</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-neutral-700">{{ formatPrice(item.priceCents) }}</span>
                            <Link
                                :href="`${base}/menu/items/${item.id}/edit`"
                                class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
                                aria-label="Edit item"
                            >
                                <Pencil class="size-4" />
                            </Link>
                            <button
                                class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-red-600"
                                type="button"
                                aria-label="Delete item"
                                @click="deleteItem(item)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </div>
                </VueDraggable>
            </section>
        </VueDraggable>

        <Dialog v-model:open="showCategoryModal">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ editingCategory ? 'Edit category' : 'New category' }}</DialogTitle>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitCategory">
                    <div class="grid gap-2">
                        <Label for="category-name">Name</Label>
                        <Input id="category-name" v-model="categoryForm.name" required />
                        <InputError :message="categoryForm.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="category-description">Description</Label>
                        <textarea
                            id="category-description"
                            v-model="categoryForm.description"
                            rows="3"
                            class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-neutral-400 focus:outline-none"
                        />
                        <InputError :message="categoryForm.errors.description" />
                    </div>
                    <InputError :message="categoryForm.errors.category" />
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showCategoryModal = false">Cancel</Button>
                        <Button type="submit" :disabled="categoryForm.processing">Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </TenantAdminLayout>
</template>
