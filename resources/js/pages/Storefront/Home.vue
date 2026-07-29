<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, inject, ref } from 'vue';
import type { Ref } from 'vue';
import AboutEditDrawer from '@/pages/Storefront/components/AboutEditDrawer.vue';
import AboutSection from '@/pages/Storefront/components/AboutSection.vue';
import ClosedBanner from '@/pages/Storefront/components/ClosedBanner.vue';
import FeaturedItemsSection from '@/pages/Storefront/components/FeaturedItemsSection.vue';
import GalleryManagerDrawer from '@/pages/Storefront/components/GalleryManagerDrawer.vue';
import GallerySection from '@/pages/Storefront/components/GallerySection.vue';
import HeroEditDrawer from '@/pages/Storefront/components/HeroEditDrawer.vue';
import HeroSection from '@/pages/Storefront/components/HeroSection.vue';
import LocationSection from '@/pages/Storefront/components/LocationSection.vue';
import QuickInfoBand from '@/pages/Storefront/components/QuickInfoBand.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    photos: App.Data.RestaurantPhotoData[];
    featuredItems: App.Data.MenuItemData[];
}>();

const page = usePage<{ auth?: { canEditSite?: boolean } }>();
const canEditSite = computed(() => Boolean(page.props.auth?.canEditSite));

const editModeRef = inject<Ref<boolean>>('storefrontEditMode', ref(false));
const editMode = computed(() => canEditSite.value && editModeRef.value);

const heroDrawerOpen = ref(false);
const aboutDrawerOpen = ref(false);
const galleryDrawerOpen = ref(false);
</script>

<template>
    <div>
        <Head :title="restaurant.name" />

        <ClosedBanner :restaurant="restaurant" />

        <HeroSection
            :restaurant="restaurant"
            :edit-mode="editMode"
            @edit-hero="heroDrawerOpen = true"
        />

        <QuickInfoBand :restaurant="restaurant" />

        <FeaturedItemsSection :items="featuredItems" />

        <AboutSection
            :restaurant="restaurant"
            :edit-mode="editMode"
            @edit-about="aboutDrawerOpen = true"
        />

        <GallerySection
            :photos="photos"
            :edit-mode="editMode"
            @edit-gallery="galleryDrawerOpen = true"
        />

        <LocationSection :restaurant="restaurant" />

        <template v-if="canEditSite">
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
        </template>
    </div>
</template>
