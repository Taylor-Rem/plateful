<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    GripVertical,
    Pencil,
    Trash2,
    Plus,
    ExternalLink,
    Eye,
    ListChecks,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';
import EmptyState from '@/components/admin/EmptyState.vue';
import MenuImportCard from '@/components/admin/MenuImportCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import IngredientsPanel from '@/components/menu/IngredientsPanel.vue';
import { isSwapSet } from '@/components/menu/splitIngredients';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import { relativeUrl } from '@/lib/relativeUrl';
import ItemConfiguratorModal from '@/pages/Storefront/components/ItemConfiguratorModal.vue';
import {
    destroy as categoriesDestroy,
    ingredientRules as categoriesIngredientRules,
    reorder as categoriesReorder,
    store as categoriesStore,
    update as categoriesUpdate,
} from '@/routes/admin/restaurant/categories';
import { update as ingredientsUpdate } from '@/routes/admin/restaurant/items/ingredients';
import { store as suggestionsStore } from '@/routes/admin/restaurant/items/suggestions';
import { store as swapSetsStore } from '@/routes/admin/restaurant/swapSets';
import { index as templatesIndex } from '@/routes/admin/restaurant/templates';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: App.Data.MenuCategoryData[];
    templates: App.Data.ItemTemplateData[];
    menuImport: {
        id: number;
        status: 'queued' | 'processing' | 'needs_review' | 'failed';
        error: string | null;
        itemCount: number;
    } | null;
    menuImportLimits: { maxFiles: number; maxFileKb: number };
}>();

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

const storefrontUrl = computed(() => {
    // Storefront lives on the tenant host. Subdomain-first; the link works for
    // both subdomain and custom-domain tenants because relative paths resolve
    // against the tenant host once the admin clicks through.
    return `//${props.restaurant.subdomain}.${window.location.host.replace(/^admin\./, '')}/`;
});

const page = usePage<{ currentRestaurantRole: string | null }>();
const isAdmin = computed(() => page.props.currentRestaurantRole === 'admin');

const localCategories = ref<App.Data.MenuCategoryData[]>([...props.categories]);

const refreshLocal = (): void => {
    localCategories.value = [...props.categories];
};

const onCategoryDragEnd = (): void => {
    const ids = localCategories.value.map((c) => c.id);
    router.post(
        categoriesReorder.url(props.restaurant.subdomain),
        { ids },
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
        categoryForm.put(
            categoriesUpdate.url({
                restaurant: props.restaurant.subdomain,
                category: editingCategory.value.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    showCategoryModal.value = false;
                    refreshLocal();
                },
            },
        );
    } else {
        categoryForm.post(categoriesStore.url(props.restaurant.subdomain), {
            preserveScroll: true,
            onSuccess: () => {
                showCategoryModal.value = false;
                categoryForm.reset();
                refreshLocal();
            },
        });
    }
};

const deleteCategory = (category: App.Data.MenuCategoryData): void => {
    const count = category.items.length;
    const warning =
        count > 0
            ? `Delete category "${category.name}" and the ${count} item${count === 1 ? '' : 's'} in it? This can't be undone.`
            : `Delete category "${category.name}"?`;

    if (!confirm(warning)) {
        return;
    }

    router.delete(
        categoriesDestroy.url({
            restaurant: props.restaurant.subdomain,
            category: category.id,
        }),
        {
            preserveScroll: true,
            onSuccess: refreshLocal,
        },
    );
};

// ----- Ingredients + preview -----
const swapSets = computed(() => props.templates.filter(isSwapSet));
const ingredientsItemId = ref<number | null>(null);
const showIngredientsModal = ref(false);

// Always the freshest copy: props refresh after every save.
const ingredientsItem = computed<App.Data.MenuItemData | null>(() => {
    if (ingredientsItemId.value === null) {
        return null;
    }

    for (const category of props.categories) {
        const found = category.items.find(
            (i) => i.id === ingredientsItemId.value,
        );

        if (found) {
            return found;
        }
    }

    return null;
});

const ingredientsCategoryName = computed(
    () =>
        props.categories.find(
            (c) => c.id === ingredientsItem.value?.menuCategoryId,
        )?.name ?? 'this category',
);

const ingredientUrls = computed(() => ({
    save: relativeUrl(
        ingredientsUpdate.url({
            restaurant: props.restaurant.subdomain,
            menuItem: ingredientsItem.value?.id ?? 0,
        }),
    ),
    swapSet: relativeUrl(swapSetsStore.url(props.restaurant.subdomain)),
    applyToCategory: relativeUrl(
        categoriesIngredientRules.url({
            restaurant: props.restaurant.subdomain,
            category: ingredientsItem.value?.menuCategoryId ?? 0,
        }),
    ),
    suggest: relativeUrl(
        suggestionsStore.url({
            restaurant: props.restaurant.subdomain,
            menuItem: ingredientsItem.value?.id ?? 0,
        }),
    ),
}));

const openIngredients = (item: App.Data.MenuItemData): void => {
    ingredientsItemId.value = item.id;
    showIngredientsModal.value = true;
};

const previewItem = ref<App.Data.MenuItemData | null>(null);
const previewOpen = ref(false);

const openPreview = (item: App.Data.MenuItemData): void => {
    previewItem.value = item;
    previewOpen.value = true;
};

const previewFromPanel = (): void => {
    if (ingredientsItem.value) {
        openPreview(ingredientsItem.value);
    }
};

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="Menu" />

        <PageHeader title="Menu">
            <template v-if="isAdmin" #actions>
                <Button as-child variant="default">
                    <a
                        :href="storefrontUrl"
                        target="_blank"
                        rel="noopener"
                        class="gap-1"
                    >
                        <ExternalLink class="size-4" /> Edit items on storefront
                    </a>
                </Button>
                <Button variant="outline" @click="openCreateCategory">
                    <Plus class="size-4" /> Add category
                </Button>
                <Button as-child variant="outline">
                    <Link :href="templatesIndex.url(restaurant.subdomain)"
                        >Templates</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <p class="mt-2 text-sm text-muted-foreground">
            Menu item and category editing now live on your storefront, so you
            can see changes the way customers do. Both can still be managed here
            too; templates live only here.
        </p>

        <MenuImportCard
            v-if="isAdmin"
            class="mt-4"
            :restaurant="restaurant"
            :menu-import="menuImport"
            :menu-import-limits="menuImportLimits"
            :has-existing-menu="localCategories.some((c) => c.items.length > 0)"
        />

        <EmptyState
            v-if="localCategories.length === 0"
            class="mt-12"
            title="No categories yet"
            :description="
                isAdmin
                    ? 'Create your first category to start building the menu.'
                    : 'No menu items have been added yet.'
            "
        >
            <template v-if="isAdmin" #actions>
                <Button @click="openCreateCategory">
                    <Plus class="size-4" /> Add category
                </Button>
            </template>
        </EmptyState>

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
                class="rounded-lg border border-border bg-card"
            >
                <header
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <button
                            v-if="isAdmin"
                            class="category-handle cursor-grab text-muted-foreground hover:text-foreground"
                            type="button"
                            aria-label="Drag category"
                        >
                            <GripVertical class="size-4" />
                        </button>
                        <h3
                            class="truncate text-lg font-medium text-foreground"
                        >
                            {{ category.name }}
                        </h3>
                        <span class="text-xs text-muted-foreground"
                            >{{ category.items.length }} item<span
                                v-if="category.items.length !== 1"
                                >s</span
                            ></span
                        >
                    </div>
                    <div v-if="isAdmin" class="flex items-center gap-1">
                        <button
                            class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                            type="button"
                            aria-label="Edit category"
                            @click="openEditCategory(category)"
                        >
                            <Pencil class="size-4" />
                        </button>
                        <button
                            class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-destructive"
                            type="button"
                            aria-label="Delete category"
                            title="Delete category"
                            @click="deleteCategory(category)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </header>

                <div
                    v-if="category.items.length === 0"
                    class="px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    No items yet.
                </div>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="item in category.items"
                        :key="item.id"
                        class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <img
                                v-if="item.imageThumbUrl"
                                :src="item.imageThumbUrl"
                                :alt="item.name"
                                class="size-8 shrink-0 rounded object-cover"
                            />
                            <span class="truncate text-foreground">{{
                                item.name
                            }}</span>
                            <span
                                v-if="item.groups.length > 0"
                                class="rounded bg-primary/10 px-1.5 py-0.5 text-xs text-primary"
                                :title="`${item.groups.length} option group(s)`"
                                >Configurable</span
                            >
                            <span
                                v-if="!item.isAvailable"
                                class="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground"
                                >Unavailable</span
                            >
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <span class="mr-2 text-foreground">{{
                                formatPrice(item.priceCents)
                            }}</span>
                            <button
                                v-if="isAdmin"
                                type="button"
                                class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                                :aria-label="`Ingredients for ${item.name}`"
                                title="Ingredients — what customers can leave out, add, or swap"
                                @click="openIngredients(item)"
                            >
                                <ListChecks class="size-4" />
                            </button>
                            <button
                                v-if="item.groups.length > 0"
                                type="button"
                                class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                                :aria-label="`Preview ${item.name} as a customer`"
                                title="Preview as customer"
                                @click="openPreview(item)"
                            >
                                <Eye class="size-4" />
                            </button>
                        </div>
                    </li>
                </ul>
            </section>
        </VueDraggable>

        <Dialog v-model:open="showIngredientsModal">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle
                        >Ingredients — {{ ingredientsItem?.name }}</DialogTitle
                    >
                </DialogHeader>
                <IngredientsPanel
                    v-if="ingredientsItem"
                    :key="ingredientsItem.id"
                    :item="ingredientsItem"
                    :swap-sets="swapSets"
                    :category-name="ingredientsCategoryName"
                    :urls="ingredientUrls"
                    @saved="refreshLocal"
                    @preview="previewFromPanel"
                />
            </DialogContent>
        </Dialog>

        <ItemConfiguratorModal
            v-if="previewItem"
            v-model:open="previewOpen"
            :item="previewItem"
            mode="preview"
        />

        <Dialog v-model:open="showCategoryModal">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        editingCategory ? 'Edit category' : 'New category'
                    }}</DialogTitle>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitCategory">
                    <div class="grid gap-2">
                        <Label for="category-name">Name</Label>
                        <Input
                            id="category-name"
                            v-model="categoryForm.name"
                            required
                        />
                        <InputError :message="categoryForm.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="category-description">Description</Label>
                        <textarea
                            id="category-description"
                            v-model="categoryForm.description"
                            rows="3"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:border-ring focus:ring-1 focus:ring-ring focus:outline-none"
                        />
                        <InputError
                            :message="categoryForm.errors.description"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showCategoryModal = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            :disabled="categoryForm.processing"
                            >Save</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
