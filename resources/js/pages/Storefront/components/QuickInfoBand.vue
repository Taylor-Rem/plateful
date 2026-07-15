<script setup lang="ts">
import { Clock, MapPin, Phone } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const addressLine = computed(() => {
    const r = props.restaurant;

    return [r.street, r.city, r.state]
        .filter((p): p is string => Boolean(p && p.trim()))
        .join(', ');
});

const telHref = computed(() => {
    const raw = props.restaurant.phone?.replace(/[^0-9+]/g, '') ?? '';

    return raw ? `tel:${raw}` : null;
});

const statusLabel = computed(
    () =>
        props.restaurant.openStatusLabel ??
        (props.restaurant.isOpen ? 'Open now' : 'Closed'),
);
</script>

<template>
    <section class="border-b border-border bg-card">
        <div
            class="mx-auto grid max-w-5xl gap-4 px-6 py-4 text-sm sm:grid-cols-3"
        >
            <div class="flex items-start gap-2.5">
                <Clock class="mt-0.5 size-4 shrink-0 text-foreground/60" />
                <div>
                    <p
                        class="text-xs font-semibold tracking-wide text-foreground/60 uppercase"
                    >
                        Hours
                    </p>
                    <p class="flex items-center gap-1.5 text-foreground">
                        <span
                            class="size-2 rounded-full"
                            :class="
                                restaurant.isOpen
                                    ? 'bg-emerald-500'
                                    : 'bg-rose-500'
                            "
                            aria-hidden="true"
                        />
                        {{ statusLabel }}
                    </p>
                </div>
            </div>

            <div v-if="addressLine" class="flex items-start gap-2.5">
                <MapPin class="mt-0.5 size-4 shrink-0 text-foreground/60" />
                <div>
                    <p
                        class="text-xs font-semibold tracking-wide text-foreground/60 uppercase"
                    >
                        Find us
                    </p>
                    <p class="text-foreground">{{ addressLine }}</p>
                </div>
            </div>

            <div v-if="telHref" class="flex items-start gap-2.5">
                <Phone class="mt-0.5 size-4 shrink-0 text-foreground/60" />
                <div>
                    <p
                        class="text-xs font-semibold tracking-wide text-foreground/60 uppercase"
                    >
                        Call
                    </p>
                    <a
                        :href="telHref"
                        class="text-foreground hover:underline"
                        >{{ restaurant.phone }}</a
                    >
                </div>
            </div>
        </div>
    </section>
</template>
