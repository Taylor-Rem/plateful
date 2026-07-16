<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    GripVertical,
    Pencil,
    Trash2,
    Plus,
    ExternalLink,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';
import InputError from '@/components/InputError.vue';
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
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: App.Data.MenuCategoryData[];
}>();

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

const base = computed(() => `/${props.restaurant.subdomain}`);

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
        `${base.value}/menu/categories/reorder`,
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
            `${base.value}/menu/categories/${editingCategory.value.id}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    showCategoryModal.value = false;
                    refreshLocal();
                },
            },
        );
    } else {
        categoryForm.post(`${base.value}/menu/categories`, {
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
    if (category.items.length > 0) {
        return;
    }

    if (!confirm(`Delete category "${category.name}"?`)) {
        return;
    }

    router.delete(`${base.value}/menu/categories/${category.id}`, {
        preserveScroll: true,
        onSuccess: refreshLocal,
    });
};
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Menu`" />

        <div class="flex items-center justify-between">
            <h2 class="text-2xl font-semibold text-foreground">Menu</h2>
            <div v-if="isAdmin" class="flex items-center gap-2">
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
                    <Link :href="`${base}/menu/templates`">Templates</Link>
                </Button>
            </div>
        </div>

        <p class="mt-2 text-sm text-muted-foreground">
            Menu item editing now lives on your storefront, so you can see
            changes the way customers do. Categories and templates are still
            managed here.
        </p>

        <div
            v-if="localCategories.length === 0"
            class="mt-12 rounded-lg border border-dashed border-border bg-card p-10 text-center"
        >
            <h3 class="text-base font-medium text-foreground">
                No categories yet
            </h3>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    isAdmin
                        ? 'Create your first category to start building the menu.'
                        : 'No menu items have been added yet.'
                }}
            </p>
            <Button v-if="isAdmin" class="mt-4" @click="openCreateCategory">
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
                class="rounded-lg border border-border bg-card"
            >
                <header
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <div class="flex items-center gap-2">
                        <button
                            v-if="isAdmin"
                            class="category-handle cursor-grab text-muted-foreground hover:text-foreground"
                            type="button"
                            aria-label="Drag category"
                        >
                            <GripVertical class="size-4" />
                        </button>
                        <h3 class="text-lg font-medium text-foreground">
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
                            class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-destructive disabled:cursor-not-allowed disabled:opacity-40"
                            type="button"
                            aria-label="Delete category"
                            :title="
                                category.items.length > 0
                                    ? 'Move or delete items first'
                                    : 'Delete category'
                            "
                            :disabled="category.items.length > 0"
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
                                v-if="item.template"
                                class="rounded bg-primary/10 px-1.5 py-0.5 text-xs text-primary"
                                :title="`Template: ${item.template.name}`"
                                >Configurable</span
                            >
                            <span
                                v-if="!item.isAvailable"
                                class="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground"
                                >Unavailable</span
                            >
                        </div>
                        <span class="text-foreground">{{
                            formatPrice(item.priceCents)
                        }}</span>
                    </li>
                </ul>
            </section>
        </VueDraggable>

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
                    <!-- Server-level error (category still has items), not a form field. -->
                    <InputError
                        :message="
                            (categoryForm.errors as Record<string, string>)
                                .category
                        "
                    />
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
    </TenantAdminLayout>
</template>
