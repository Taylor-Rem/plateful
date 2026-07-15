<script setup lang="ts">
import { Pencil, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    photos: App.Data.RestaurantPhotoData[];
    editMode: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit-gallery'): void;
}>();

const hasAny = computed(() => props.photos.length > 0);

const lightboxIndex = ref<number | null>(null);

const openLightbox = (idx: number): void => {
    if (props.editMode) {
        emit('edit-gallery');

        return;
    }

    lightboxIndex.value = idx;
};

const closeLightbox = (): void => {
    lightboxIndex.value = null;
};

const lightboxPhoto = computed(() =>
    lightboxIndex.value === null
        ? null
        : (props.photos[lightboxIndex.value] ?? null),
);
</script>

<template>
    <section
        v-if="hasAny || editMode"
        id="gallery"
        class="mx-auto max-w-5xl scroll-mt-16 px-6 py-12"
    >
        <div class="mb-6 flex items-center justify-between gap-3">
            <h2
                class="inline-block border-b-2 pb-1 text-2xl font-semibold text-foreground"
                :style="{ borderColor: 'var(--brand-primary)' }"
            >
                Photos
            </h2>
            <button
                v-if="editMode"
                type="button"
                class="inline-flex items-center gap-1 rounded-full bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-sm ring-1 ring-border hover:bg-muted"
                @click="emit('edit-gallery')"
            >
                <Pencil class="size-3.5" /> Manage photos
            </button>
        </div>

        <div
            v-if="hasAny"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4"
        >
            <button
                v-for="(photo, idx) in photos"
                :key="photo.id"
                type="button"
                class="group relative aspect-square overflow-hidden rounded-lg bg-muted focus:ring-2 focus:ring-ring focus:outline-none"
                @click="openLightbox(idx)"
            >
                <img
                    :src="
                        photo.imageThumbUrl ??
                        photo.imageMediumUrl ??
                        photo.imageUrl ??
                        ''
                    "
                    :alt="photo.caption ?? ''"
                    class="size-full object-cover transition group-hover:scale-105"
                    loading="lazy"
                />
                <span
                    v-if="photo.caption"
                    class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5 text-left text-xs text-white opacity-0 transition group-hover:opacity-100"
                >
                    {{ photo.caption }}
                </span>
            </button>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            Add 6–12 photos so customers can see your space and your food.
        </p>

        <!-- Customer lightbox -->
        <div
            v-if="lightboxPhoto"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="closeLightbox"
            @keydown.esc="closeLightbox"
        >
            <button
                type="button"
                class="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"
                aria-label="Close"
                @click="closeLightbox"
            >
                <X class="size-5" />
            </button>
            <figure class="max-h-full max-w-5xl">
                <img
                    :src="
                        lightboxPhoto.imageUrl ??
                        lightboxPhoto.imageMediumUrl ??
                        ''
                    "
                    :alt="lightboxPhoto.caption ?? ''"
                    class="max-h-[80vh] w-auto rounded-lg"
                />
                <figcaption
                    v-if="lightboxPhoto.caption"
                    class="mt-3 text-center text-sm text-white/90"
                >
                    {{ lightboxPhoto.caption }}
                </figcaption>
            </figure>
        </div>
    </section>
</template>
