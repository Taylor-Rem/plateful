<script setup lang="ts">
import { computed } from 'vue';
import { Pencil } from 'lucide-vue-next';

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
        class="mx-auto max-w-5xl scroll-mt-16 px-6 py-12"
        :class="{ 'cursor-pointer group/about relative': editMode }"
        @click="editMode && emit('edit-about')"
    >
        <span
            v-if="editMode"
            class="absolute right-6 top-6 z-10 inline-flex items-center gap-1 rounded-full bg-card/95 px-2 py-1 text-xs font-medium text-foreground shadow-sm"
        >
            <Pencil class="size-3.5" /> Edit about
        </span>

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
        <p v-else class="text-sm text-muted-foreground">
            Add your story so customers know who they're ordering from.
        </p>
    </section>
</template>
