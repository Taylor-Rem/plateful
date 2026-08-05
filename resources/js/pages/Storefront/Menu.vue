<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Pencil, Plus } from 'lucide-vue-next';
import { computed, inject, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { toast } from 'vue-sonner';
import CategoryEditDialog from '@/pages/Storefront/components/CategoryEditDialog.vue';
import ClosedBanner from '@/pages/Storefront/components/ClosedBanner.vue';
import ItemConfiguratorModal from '@/pages/Storefront/components/ItemConfiguratorModal.vue';
import MenuItemDeleteDialog from '@/pages/Storefront/components/MenuItemDeleteDialog.vue';
import MenuItemEditDrawer from '@/pages/Storefront/components/MenuItemEditDrawer.vue';

type EditorPayload = {
    categories: Array<{ id: number; name: string }>;
    templates: App.Data.ItemTemplateData[];
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: App.Data.MenuCategoryData[];
    editor: EditorPayload | null;
}>();

const page = usePage<{ auth?: { canEditMenu?: boolean } }>();
const canEditMenu = computed(
    () => Boolean(page.props.auth?.canEditMenu) && props.editor !== null,
);

// Edit mode is provided by StorefrontLayout (persisted in localStorage).
const editModeRef = inject<Ref<boolean>>('storefrontEditMode', ref(false));
const editMode = computed(() => canEditMenu.value && editModeRef.value);

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

const configuratorOpen = ref(false);
const activeItem = ref<App.Data.MenuItemData | null>(null);

const drawerOpen = ref(false);
const editingItem = ref<App.Data.MenuItemData | null>(null);
const deleteDialogOpen = ref(false);
const deleteTarget = ref<App.Data.MenuItemData | null>(null);

const openCreate = (): void => {
    editingItem.value = null;
    drawerOpen.value = true;
};

const openEdit = (item: App.Data.MenuItemData): void => {
    editingItem.value = item;
    drawerOpen.value = true;
};

const onDeleteRequested = (item: App.Data.MenuItemData): void => {
    drawerOpen.value = false;
    deleteTarget.value = item;
    deleteDialogOpen.value = true;
};

// ----- Category management (edit mode) -----
const categoryDialogOpen = ref(false);
const editingCategory = ref<App.Data.MenuCategoryData | null>(null);

const openCreateCategory = (): void => {
    editingCategory.value = null;
    categoryDialogOpen.value = true;
};

const openEditCategory = (category: App.Data.MenuCategoryData): void => {
    editingCategory.value = category;
    categoryDialogOpen.value = true;
};

const onCategoryDeleteRequested = (
    category: App.Data.MenuCategoryData,
): void => {
    const count = category.items.length;
    const warning =
        count > 0
            ? `Delete the "${category.name}" category and the ${count} item${count === 1 ? '' : 's'} in it? This can't be undone.`
            : `Delete the "${category.name}" category?`;

    if (!window.confirm(warning)) {
        return;
    }

    router.delete(`/admin/menu/categories/${category.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            categoryDialogOpen.value = false;
            toast.success('Category deleted.');
        },
    });
};

// Local order so the arrows respond instantly; the server confirms behind it.
const localCategoryOrder = ref<number[]>([]);

watch(
    () => props.categories,
    (list) => {
        localCategoryOrder.value = list.map((c) => c.id);
    },
    { immediate: true, deep: true },
);

const orderedCategories = computed(() => {
    const byId = new Map(props.categories.map((c) => [c.id, c]));

    return localCategoryOrder.value
        .map((id) => byId.get(id))
        .filter((c): c is App.Data.MenuCategoryData => Boolean(c));
});

const moveCategory = (idx: number, delta: number): void => {
    const target = idx + delta;

    if (target < 0 || target >= localCategoryOrder.value.length) {
        return;
    }

    const copy = [...localCategoryOrder.value];
    const [moved] = copy.splice(idx, 1);
    copy.splice(target, 0, moved);
    localCategoryOrder.value = copy;

    router.post(
        '/admin/menu/categories/reorder',
        { ids: copy },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                localCategoryOrder.value = props.categories.map((c) => c.id);
                toast.error('Could not reorder.');
            },
        },
    );
};

// Every item opens the configurator — even without options it's where the
// customer confirms quantity and special instructions before the cart.
const onItemClick = (item: App.Data.MenuItemData): void => {
    if (editMode.value) {
        openEdit(item);

        return;
    }

    activeItem.value = item;
    configuratorOpen.value = true;
};

const onAddToCart = (payload: {
    itemId: number;
    selections: Array<{ groupId: number; optionIds: number[] }>;
    unitPriceCents: number;
    quantity: number;
    notes: string;
}): void => {
    const name = activeItem.value?.name ?? 'Item';
    const optionIds = payload.selections.flatMap((s) => s.optionIds);
    router.post(
        `/cart/items/${payload.itemId}`,
        {
            quantity: payload.quantity,
            option_ids: optionIds,
            notes: payload.notes || null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success(`Added ${name} to cart`),
            onError: () => toast.error('Could not add to cart.'),
        },
    );
};
</script>

<template>
    <div>
        <Head :title="`Menu — ${restaurant.name}`" />

        <ClosedBanner :restaurant="restaurant" />

        <header class="border-b border-border bg-card">
            <div class="mx-auto max-w-5xl px-6 py-8">
                <h1
                    class="text-3xl font-bold tracking-tight text-foreground sm:text-4xl"
                >
                    Menu
                </h1>
                <p
                    v-if="restaurant.openStatusLabel"
                    class="mt-2 text-sm text-foreground/70"
                >
                    {{ restaurant.openStatusLabel }}
                </p>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-10">
            <p
                v-if="categories.length === 0"
                class="text-sm text-muted-foreground"
            >
                No menu items yet.
            </p>
            <section
                v-for="(category, categoryIdx) in orderedCategories"
                :key="category.id"
                class="mb-10"
            >
                <div class="mb-4 flex items-center gap-2">
                    <h2
                        class="inline-block border-b-2 pb-1 text-2xl font-semibold text-foreground"
                        :style="{ borderColor: 'var(--brand-secondary)' }"
                    >
                        {{ category.name }}
                    </h2>
                    <template v-if="editMode">
                        <button
                            type="button"
                            class="rounded-full bg-card/90 p-1.5 text-muted-foreground shadow-sm transition hover:text-foreground"
                            aria-label="Edit category"
                            @click="openEditCategory(category)"
                        >
                            <Pencil class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-full bg-card/90 p-1.5 text-muted-foreground shadow-sm transition hover:text-foreground disabled:opacity-30"
                            :disabled="categoryIdx === 0"
                            aria-label="Move category up"
                            @click="moveCategory(categoryIdx, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-full bg-card/90 p-1.5 text-muted-foreground shadow-sm transition hover:text-foreground disabled:opacity-30"
                            :disabled="
                                categoryIdx === orderedCategories.length - 1
                            "
                            aria-label="Move category down"
                            @click="moveCategory(categoryIdx, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </button>
                    </template>
                </div>
                <p
                    v-if="category.description"
                    class="-mt-3 mb-4 max-w-2xl text-sm text-muted-foreground"
                >
                    {{ category.description }}
                </p>
                <ul class="grid gap-4 md:grid-cols-2">
                    <li
                        v-for="item in category.items"
                        :key="item.id"
                        class="group relative cursor-pointer overflow-hidden rounded-lg border border-border bg-card text-left shadow-sm transition hover:shadow-md focus:ring-2 focus:ring-ring focus:outline-none"
                        :class="{ 'opacity-60': editMode && !item.isAvailable }"
                        tabindex="0"
                        role="button"
                        @click="onItemClick(item)"
                        @keydown.enter.prevent="onItemClick(item)"
                        @keydown.space.prevent="onItemClick(item)"
                    >
                        <span
                            v-if="editMode && !item.isAvailable"
                            class="absolute top-2 right-2 z-10 rounded bg-amber-200 px-1.5 py-0.5 text-xs font-medium text-amber-900"
                        >
                            Unavailable
                        </span>
                        <span
                            v-if="editMode"
                            class="absolute top-2 left-2 z-10 rounded-full bg-card/90 p-1 text-muted-foreground shadow-sm"
                            aria-hidden="true"
                        >
                            <Pencil class="size-3.5" />
                        </span>
                        <div
                            v-if="item.imageMediumUrl"
                            class="aspect-[4/3] w-full overflow-hidden bg-muted"
                        >
                            <img
                                :src="item.imageMediumUrl"
                                :alt="item.name"
                                class="size-full object-cover"
                            />
                        </div>
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div>
                                <h3 class="font-medium text-foreground">
                                    {{ item.name }}
                                </h3>
                                <p
                                    v-if="item.description"
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    {{ item.description }}
                                </p>
                                <p
                                    v-if="item.template"
                                    class="mt-2 text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    Customize
                                </p>
                            </div>
                            <span
                                class="font-semibold whitespace-nowrap"
                                :style="{ color: 'var(--brand-primary)' }"
                            >
                                {{ formatPrice(item.priceCents) }}
                            </span>
                        </div>
                    </li>
                    <li
                        v-if="editMode"
                        class="flex min-h-32 cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-border bg-card/50 text-sm text-muted-foreground transition hover:border-primary hover:text-foreground"
                        tabindex="0"
                        role="button"
                        @click="openCreate"
                        @keydown.enter.prevent="openCreate"
                        @keydown.space.prevent="openCreate"
                    >
                        <Plus class="mr-1 size-4" /> Add item
                    </li>
                </ul>
            </section>

            <button
                v-if="editMode"
                type="button"
                class="flex w-full cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-border bg-card/50 px-4 py-6 text-sm text-muted-foreground transition hover:border-primary hover:text-foreground"
                @click="openCreateCategory"
            >
                <Plus class="mr-1 size-4" /> Add category
            </button>
        </main>

        <ItemConfiguratorModal
            v-if="activeItem"
            v-model:open="configuratorOpen"
            :item="activeItem"
            @add-to-cart="onAddToCart"
        />

        <template v-if="canEditMenu && editor">
            <MenuItemEditDrawer
                v-model:open="drawerOpen"
                :item="editingItem"
                :categories="editor.categories"
                :templates="editor.templates"
                @delete-requested="onDeleteRequested"
            />
            <MenuItemDeleteDialog
                v-model:open="deleteDialogOpen"
                :item="deleteTarget"
            />
            <CategoryEditDialog
                v-model:open="categoryDialogOpen"
                :category="editingCategory"
                @delete-requested="onCategoryDeleteRequested"
            />
        </template>
    </div>
</template>
