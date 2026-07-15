<script setup lang="ts">
import { Clock, ImagePlus, MapPin, Pencil } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    editMode: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit-hero'): void;
}>();

const hasImage = computed(() => Boolean(props.restaurant.heroImageUrl));

const ctaLabel = computed(
    () => props.restaurant.heroCtaLabel?.trim() || 'Order online',
);
const ctaHref = computed(() => props.restaurant.heroCtaUrl?.trim() || '/menu');

const addressLine = computed(() => {
    const r = props.restaurant;
    const parts = [r.street, r.city, r.state].filter((p): p is string =>
        Boolean(p && p.trim()),
    );

    return parts.join(', ');
});

// When the hero has an image, content sits on a dark scrim → white text + brand CTA.
// When there's no image, the section IS the brand color → text uses the brand-foreground
// CSS var and the CTA inverts (foreground bg + primary text) so it visibly stands out
// against the brand-color background.
const sectionStyle = computed(() =>
    hasImage.value
        ? {}
        : {
              backgroundColor: 'var(--brand-primary)',
              color: 'var(--brand-primary-foreground)',
          },
);

const ctaStyle = computed(() =>
    hasImage.value
        ? {
              backgroundColor: 'var(--brand-primary)',
              color: 'var(--brand-primary-foreground)',
          }
        : {
              backgroundColor: 'var(--brand-primary-foreground)',
              color: 'var(--brand-primary)',
          },
);
</script>

<template>
    <section class="relative isolate overflow-hidden" :style="sectionStyle">
        <img
            v-if="hasImage"
            :src="restaurant.heroImageUrl ?? ''"
            :alt="restaurant.name"
            class="absolute inset-0 -z-10 size-full object-cover"
        />
        <div
            v-if="hasImage"
            class="absolute inset-0 -z-10 bg-gradient-to-b from-black/30 via-black/40 to-black/70"
            aria-hidden="true"
        />

        <button
            v-if="editMode"
            type="button"
            class="absolute top-3 right-3 z-10 inline-flex items-center gap-1 rounded-full bg-card/95 px-2.5 py-1.5 text-xs font-medium text-foreground shadow-sm ring-1 ring-border hover:bg-card"
            aria-label="Edit hero"
            @click="emit('edit-hero')"
        >
            <Pencil class="size-3.5" /> Edit hero
        </button>

        <div
            class="mx-auto flex max-w-5xl flex-col gap-5 px-6 py-20 sm:py-28"
            :class="hasImage ? 'text-white' : ''"
        >
            <div class="flex items-center gap-4">
                <img
                    v-if="restaurant.logoMediumUrl"
                    :src="restaurant.logoMediumUrl"
                    :alt="`${restaurant.name} logo`"
                    class="size-16 shrink-0 rounded-lg bg-white object-contain p-1 shadow"
                />
                <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
                    {{ restaurant.name }}
                </h1>
            </div>

            <p
                v-if="restaurant.heroTagline"
                class="max-w-2xl text-lg opacity-95 sm:text-xl"
            >
                {{ restaurant.heroTagline }}
            </p>
            <p
                v-else-if="restaurant.description"
                class="max-w-2xl text-lg opacity-90"
            >
                {{ restaurant.description }}
            </p>

            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1"
                    :class="
                        hasImage
                            ? 'bg-black/40 text-white ring-white/20 backdrop-blur'
                            : 'bg-black/10 ring-black/10'
                    "
                >
                    <span
                        class="size-2.5 rounded-full"
                        :class="
                            restaurant.isOpen ? 'bg-emerald-400' : 'bg-rose-400'
                        "
                        aria-hidden="true"
                    />
                    <Clock class="size-3.5" />
                    {{
                        restaurant.openStatusLabel ??
                        (restaurant.isOpen ? 'Open now' : 'Closed')
                    }}
                </span>
                <span
                    v-if="addressLine"
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1"
                    :class="
                        hasImage
                            ? 'bg-black/40 text-white ring-white/20 backdrop-blur'
                            : 'bg-black/10 ring-black/10'
                    "
                >
                    <MapPin class="size-3.5" />
                    {{ addressLine }}
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <a
                    :href="ctaHref"
                    class="inline-flex items-center justify-center rounded-md px-5 py-3 text-base font-semibold shadow-sm transition hover:brightness-110 focus:ring-2 focus:ring-offset-2 focus:outline-none"
                    :style="ctaStyle"
                    @click.stop
                >
                    {{ ctaLabel }}
                </a>
                <button
                    v-if="editMode && !hasImage"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-current/40 px-4 py-2.5 text-sm font-medium opacity-90 hover:opacity-100"
                    @click="emit('edit-hero')"
                >
                    <ImagePlus class="size-4" /> Add a hero image
                </button>
            </div>
        </div>
    </section>
</template>
