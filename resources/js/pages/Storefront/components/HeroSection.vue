<script setup lang="ts">
import { computed } from 'vue';
import { Clock, MapPin, Pencil } from 'lucide-vue-next';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    editMode: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit-hero'): void;
}>();

const ctaLabel = computed(() => props.restaurant.heroCtaLabel?.trim() || 'Order online');
const ctaHref = computed(() => props.restaurant.heroCtaUrl?.trim() || '/menu');

const addressLine = computed(() => {
    const r = props.restaurant;
    const parts = [r.street, r.city, r.state].filter((p): p is string => Boolean(p && p.trim()));
    return parts.join(', ');
});

const onEditClick = (): void => {
    if (props.editMode) {
        emit('edit-hero');
    }
};
</script>

<template>
    <section
        class="relative isolate overflow-hidden"
        :class="{ 'cursor-pointer group/hero': editMode }"
        :style="restaurant.heroImageUrl ? {} : { backgroundColor: 'var(--brand-primary)', color: 'var(--brand-primary-foreground)' }"
        @click="onEditClick"
    >
        <img
            v-if="restaurant.heroImageUrl"
            :src="restaurant.heroImageUrl"
            :alt="restaurant.name"
            class="absolute inset-0 -z-10 size-full object-cover"
        />
        <div
            v-if="restaurant.heroImageUrl"
            class="absolute inset-0 -z-10 bg-gradient-to-b from-black/30 via-black/40 to-black/70"
            aria-hidden="true"
        />

        <span
            v-if="editMode"
            class="absolute right-3 top-3 z-10 flex items-center gap-1 rounded-full bg-card/95 px-2 py-1 text-xs font-medium text-foreground shadow-sm opacity-90 group-hover/hero:opacity-100"
        >
            <Pencil class="size-3.5" /> Edit hero
        </span>

        <div
            class="mx-auto flex max-w-5xl flex-col gap-5 px-6 py-20 sm:py-28"
            :class="restaurant.heroImageUrl ? 'text-white' : ''"
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
                class="max-w-2xl text-lg sm:text-xl opacity-95"
            >
                {{ restaurant.heroTagline }}
            </p>
            <p
                v-else-if="restaurant.description"
                class="max-w-2xl text-lg opacity-90"
            >
                {{ restaurant.description }}
            </p>

            <div class="flex flex-wrap items-center gap-4 text-sm opacity-95">
                <span class="inline-flex items-center gap-1.5">
                    <span
                        class="size-2 rounded-full"
                        :class="restaurant.isOpen ? 'bg-emerald-400' : 'bg-rose-400'"
                        aria-hidden="true"
                    />
                    <Clock class="size-4" />
                    <span>{{ restaurant.openStatusLabel ?? (restaurant.isOpen ? 'Open now' : 'Closed') }}</span>
                </span>
                <span v-if="addressLine" class="inline-flex items-center gap-1.5">
                    <MapPin class="size-4" />
                    {{ addressLine }}
                </span>
            </div>

            <div>
                <a
                    :href="ctaHref"
                    class="inline-flex items-center justify-center rounded-md px-5 py-3 text-base font-semibold shadow-sm transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2"
                    :style="{
                        backgroundColor: 'var(--brand-primary)',
                        color: 'var(--brand-primary-foreground)',
                    }"
                    @click.stop
                >
                    {{ ctaLabel }}
                </a>
            </div>
        </div>
    </section>
</template>
