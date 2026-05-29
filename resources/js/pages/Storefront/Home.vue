<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, inject, ref, type Ref } from 'vue';
import { Clock, UtensilsCrossed } from 'lucide-vue-next';
import HeroSection from '@/pages/Storefront/components/HeroSection.vue';
import HeroEditDrawer from '@/pages/Storefront/components/HeroEditDrawer.vue';
import AboutSection from '@/pages/Storefront/components/AboutSection.vue';
import AboutEditDrawer from '@/pages/Storefront/components/AboutEditDrawer.vue';
import LocationSection from '@/pages/Storefront/components/LocationSection.vue';
import GallerySection from '@/pages/Storefront/components/GallerySection.vue';
import GalleryManagerDrawer from '@/pages/Storefront/components/GalleryManagerDrawer.vue';

type BrandPalette = {
    primary: string;
    primaryForeground: string;
    secondary: string;
    secondaryForeground: string;
};

defineProps<{
    restaurant: App.Data.RestaurantData;
    photos: App.Data.RestaurantPhotoData[];
    brand: BrandPalette;
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
            :edit-mode="editMode"
            @edit-hero="heroDrawerOpen = true"
        />

        <section class="mx-auto max-w-5xl px-6 py-10 text-center">
            <Link
                href="/menu"
                class="inline-flex items-center justify-center gap-2 rounded-md px-6 py-3 text-base font-semibold shadow-sm transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2"
                :style="{
                    backgroundColor: 'var(--brand-primary)',
                    color: 'var(--brand-primary-foreground)',
                }"
            >
                <UtensilsCrossed class="size-5" />
                View the full menu
            </Link>
        </section>

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
