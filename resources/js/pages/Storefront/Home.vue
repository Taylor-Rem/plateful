<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { computed, onMounted, ref } from 'vue';
import { Clock, Pencil, Plus } from 'lucide-vue-next';
import ItemConfiguratorModal from '@/pages/Storefront/components/ItemConfiguratorModal.vue';
import AdminBar from '@/pages/Storefront/components/AdminBar.vue';
import MenuItemEditDrawer from '@/pages/Storefront/components/MenuItemEditDrawer.vue';
import MenuItemDeleteDialog from '@/pages/Storefront/components/MenuItemDeleteDialog.vue';
import HeroSection from '@/pages/Storefront/components/HeroSection.vue';
import HeroEditDrawer from '@/pages/Storefront/components/HeroEditDrawer.vue';
import AboutSection from '@/pages/Storefront/components/AboutSection.vue';
import AboutEditDrawer from '@/pages/Storefront/components/AboutEditDrawer.vue';
import LocationSection from '@/pages/Storefront/components/LocationSection.vue';
import GallerySection from '@/pages/Storefront/components/GallerySection.vue';
import GalleryManagerDrawer from '@/pages/Storefront/components/GalleryManagerDrawer.vue';
import Footer from '@/pages/Storefront/components/Footer.vue';
import SocialLinksEditDrawer from '@/pages/Storefront/components/SocialLinksEditDrawer.vue';

type BrandPalette = {
    primary: string;
    primaryForeground: string;
    secondary: string;
    secondaryForeground: string;
};

type EditorPayload = {
    categories: Array<{ id: number; name: string }>;
    templates: App.Data.ItemTemplateData[];
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    categories: App.Data.MenuCategoryData[];
    photos: App.Data.RestaurantPhotoData[];
    brand: BrandPalette;
    editor: EditorPayload | null;
}>();

const page = usePage<{ auth?: { canEditMenu?: boolean } }>();
const canEditMenu = computed(() => Boolean(page.props.auth?.canEditMenu) && props.editor !== null);

const EDIT_MODE_KEY = 'plateful:storefront:editMode';
const editMode = ref(false);

onMounted(() => {
    if (canEditMenu.value && typeof window !== 'undefined') {
        editMode.value = window.localStorage.getItem(EDIT_MODE_KEY) === '1';
    }
});

const setEditMode = (value: boolean): void => {
    editMode.value = value;
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(EDIT_MODE_KEY, value ? '1' : '0');
    }
};

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

const configuratorOpen = ref(false);
const activeItem = ref<App.Data.MenuItemData | null>(null);

// Editor state
const drawerOpen = ref(false);
const editingItem = ref<App.Data.MenuItemData | null>(null);
const deleteDialogOpen = ref(false);
const deleteTarget = ref<App.Data.MenuItemData | null>(null);
const heroDrawerOpen = ref(false);
const aboutDrawerOpen = ref(false);
const galleryDrawerOpen = ref(false);
const socialDrawerOpen = ref(false);

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

const onItemClick = (item: App.Data.MenuItemData): void => {
    if (editMode.value) {
        openEdit(item);
        return;
    }
    if (item.template) {
        activeItem.value = item;
        configuratorOpen.value = true;
        return;
    }
    router.post(
        `/cart/items/${item.id}`,
        { quantity: 1, option_ids: [] },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success(`Added ${item.name} to cart`);
            },
            onError: () => {
                toast.error('Could not add to cart.');
            },
        },
    );
};

const onAddToCart = (payload: { itemId: number; selections: Array<{ groupId: number; optionIds: number[] }>; unitPriceCents: number }): void => {
    const name = activeItem.value?.name ?? 'Item';
    const optionIds = payload.selections.flatMap((s) => s.optionIds);
    router.post(
        `/cart/items/${payload.itemId}`,
        { quantity: 1, option_ids: optionIds },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success(`Added ${name} to cart`);
            },
            onError: () => {
                toast.error('Could not add to cart.');
            },
        },
    );
};
</script>

<template>
    <div>
        <Head :title="restaurant.name" />

        <AdminBar
            v-if="canEditMenu"
            :edit-mode="editMode"
            @update:edit-mode="setEditMode"
            @add-item="openCreate"
        />

        <div
            v-if="restaurant.isOpen === false"
            class="border-b border-amber-300 bg-amber-100 text-amber-900"
        >
            <div class="mx-auto flex max-w-5xl items-center gap-2 px-6 py-3 text-sm">
                <Clock class="size-4 shrink-0" />
                <span>
                    <strong class="font-semibold">We're currently closed.</strong>
                    {{ restaurant.nextOpenLabel }}
                </span>
            </div>
        </div>

        <HeroSection
            :restaurant="restaurant"
            :edit-mode="canEditMenu && editMode"
            @edit-hero="heroDrawerOpen = true"
        />

        <AboutSection
            :restaurant="restaurant"
            :edit-mode="canEditMenu && editMode"
            @edit-about="aboutDrawerOpen = true"
        />

        <GallerySection
            :photos="photos"
            :edit-mode="canEditMenu && editMode"
            @edit-gallery="galleryDrawerOpen = true"
        />

        <main id="menu" class="mx-auto max-w-5xl px-6 py-10 scroll-mt-16">
            <section
                v-for="category in categories"
                :key="category.id"
                class="mb-10"
            >
                <h2
                    class="mb-4 inline-block border-b-2 pb-1 text-2xl font-semibold text-foreground"
                    :style="{ borderColor: 'var(--brand-primary)' }"
                >
                    {{ category.name }}
                </h2>
                <ul class="grid gap-4 md:grid-cols-2">
                    <li
                        v-for="item in category.items"
                        :key="item.id"
                        class="group relative cursor-pointer overflow-hidden rounded-lg border border-border bg-card text-left shadow-sm transition hover:shadow-md focus:outline-none focus:ring-2 focus:ring-ring"
                        :class="{ 'opacity-60': editMode && !item.isAvailable }"
                        tabindex="0"
                        role="button"
                        @click="onItemClick(item)"
                        @keydown.enter.prevent="onItemClick(item)"
                        @keydown.space.prevent="onItemClick(item)"
                    >
                        <span
                            v-if="editMode && !item.isAvailable"
                            class="absolute right-2 top-2 z-10 rounded bg-amber-200 px-1.5 py-0.5 text-xs font-medium text-amber-900"
                        >
                            Unavailable
                        </span>
                        <span
                            v-if="editMode"
                            class="absolute left-2 top-2 z-10 rounded-full bg-card/90 p-1 text-muted-foreground shadow-sm"
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
                                    class="mt-2 text-xs uppercase tracking-wide text-muted-foreground"
                                >
                                    Customize
                                </p>
                            </div>
                            <span
                                class="whitespace-nowrap font-semibold"
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
        </main>

        <LocationSection :restaurant="restaurant" />

        <Footer
            :restaurant="restaurant"
            :edit-mode="canEditMenu && editMode"
            @edit-social="socialDrawerOpen = true"
        />

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
            <HeroEditDrawer
                v-model:open="heroDrawerOpen"
                :restaurant="restaurant"
            />
            <AboutEditDrawer
                v-model:open="aboutDrawerOpen"
                :restaurant="restaurant"
            />
            <GalleryManagerDrawer
                v-model:open="galleryDrawerOpen"
                :photos="photos"
            />
            <SocialLinksEditDrawer
                v-model:open="socialDrawerOpen"
                :restaurant="restaurant"
            />
        </template>
    </div>
</template>
