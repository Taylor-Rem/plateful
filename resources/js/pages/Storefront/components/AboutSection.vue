<script setup lang="ts">
import { computed } from 'vue';
import { ImagePlus, Pencil } from 'lucide-vue-next';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    editMode: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit-about'): void;
}>();

const hasContent = computed(() => Boolean(props.restaurant.aboutBody) || Boolean(props.restaurant.aboutImageUrl));

const paragraphs = computed(() => (props.restaurant.aboutBody ?? '').split(/\n+/).map((p) => p.trim()).filter(Boolean));
</script>

<template>
    <section
        v-if="hasContent || editMode"
        id="about"
        class="relative mx-auto max-w-5xl scroll-mt-16 px-6 py-12"
    >
        <button
            v-if="editMode"
            type="button"
            class="absolute right-6 top-6 z-10 inline-flex items-center gap-1 rounded-full bg-card/95 px-2.5 py-1.5 text-xs font-medium text-foreground shadow-sm ring-1 ring-border hover:bg-card"
            aria-label="Edit about"
            @click="emit('edit-about')"
        >
            <Pencil class="size-3.5" /> Edit about
        </button>

        <h2
            class="mb-6 inline-block border-b-2 pb-1 text-2xl font-semibold text-foreground"
            :style="{ borderColor: 'var(--brand-primary)' }"
        >
            About
        </h2>

        <div v-if="hasContent" class="grid gap-8 md:grid-cols-2 md:items-center">
            <div v-if="restaurant.aboutImageMediumUrl || restaurant.aboutImageUrl" class="order-1 overflow-hidden rounded-lg bg-muted">
                <img
                    :src="restaurant.aboutImageMediumUrl ?? restaurant.aboutImageUrl ?? ''"
                    :alt="`${restaurant.name}`"
                    class="size-full object-cover"
                />
            </div>
            <div class="order-2 space-y-4 text-base leading-relaxed text-foreground/90">
                <p v-for="(para, idx) in paragraphs" :key="idx">{{ para }}</p>
            </div>
        </div>
        <div
            v-else
            class="rounded-lg border-2 border-dashed border-border bg-muted/30 p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">Tell customers who you are.</p>
            <button
                type="button"
                class="mt-3 inline-flex items-center gap-2 rounded-md bg-card px-4 py-2 text-sm font-medium text-foreground ring-1 ring-border hover:bg-muted"
                @click="emit('edit-about')"
            >
                <ImagePlus class="size-4" /> Write your story
            </button>
        </div>
    </section>
</template>
