<script setup lang="ts">
import { computed } from 'vue';
import { MapPin, Phone } from 'lucide-vue-next';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const fullAddress = computed(() => {
    const r = props.restaurant;
    const line1 = [r.street, r.street2].filter(Boolean).join(' ');
    const line2 = [r.city, r.state].filter(Boolean).join(', ');
    return [line1, line2, r.postalCode].filter((p): p is string => Boolean(p && p.trim())).join(', ');
});

const mapsEmbedSrc = computed(() => {
    if (!fullAddress.value) return null;
    return `https://www.google.com/maps?q=${encodeURIComponent(fullAddress.value)}&output=embed`;
});

const mapsDirectionsHref = computed(() => {
    if (!fullAddress.value) return null;
    return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(fullAddress.value)}`;
});

const telHref = computed(() => {
    const raw = props.restaurant.phone?.replace(/[^0-9+]/g, '') ?? '';
    return raw ? `tel:${raw}` : null;
});

const formatTime = (hhmm: string): string => {
    const [hStr, mStr] = hhmm.split(':');
    const h = parseInt(hStr, 10);
    const m = parseInt(mStr, 10);
    if (Number.isNaN(h)) return hhmm;
    const period = h >= 12 ? 'PM' : 'AM';
    const hour12 = ((h + 11) % 12) + 1;
    const minutes = m === 0 ? '' : `:${String(m).padStart(2, '0')}`;
    return `${hour12}${minutes} ${period}`;
};

const hasAnyHours = computed(() => props.restaurant.hoursByDay.some((day) => day.length > 0));
</script>

<template>
    <section id="location" class="bg-muted/30">
        <div class="mx-auto max-w-5xl scroll-mt-16 px-6 py-12">
            <h2
                class="mb-6 inline-block border-b-2 pb-1 text-2xl font-semibold text-foreground"
                :style="{ borderColor: 'var(--brand-primary)' }"
            >
                Visit us
            </h2>

            <div class="grid gap-8 md:grid-cols-2">
                <div class="space-y-5">
                    <div v-if="fullAddress" class="flex items-start gap-3">
                        <MapPin class="mt-0.5 size-5 shrink-0 text-foreground/70" />
                        <div>
                            <p class="font-medium text-foreground">{{ restaurant.street }}<span v-if="restaurant.street2">, {{ restaurant.street2 }}</span></p>
                            <p class="text-sm text-foreground/70">{{ restaurant.city }}<span v-if="restaurant.state">, {{ restaurant.state }}</span> <span v-if="restaurant.postalCode">{{ restaurant.postalCode }}</span></p>
                            <a
                                v-if="mapsDirectionsHref"
                                :href="mapsDirectionsHref"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-1 inline-block text-sm font-medium underline"
                                :style="{ color: 'var(--brand-primary)' }"
                            >
                                Get directions
                            </a>
                        </div>
                    </div>

                    <div v-if="telHref" class="flex items-start gap-3">
                        <Phone class="mt-0.5 size-5 shrink-0 text-foreground/70" />
                        <a :href="telHref" class="font-medium text-foreground hover:underline">
                            {{ restaurant.phone }}
                        </a>
                    </div>

                    <div v-if="hasAnyHours">
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-foreground/70">Hours</h3>
                        <dl class="divide-y divide-border rounded-md border border-border bg-card">
                            <div v-for="(windows, dow) in restaurant.hoursByDay" :key="dow" class="flex items-baseline justify-between px-3 py-2 text-sm">
                                <dt class="font-medium text-foreground">{{ DAY_NAMES[dow] }}</dt>
                                <dd class="text-right text-foreground/80">
                                    <span v-if="windows.length === 0" class="text-muted-foreground">Closed</span>
                                    <span v-else>
                                        <span v-for="(w, i) in windows" :key="i">
                                            <span v-if="i > 0">, </span>{{ formatTime(w.opensAt) }} – {{ formatTime(w.closesAt) }}
                                        </span>
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div v-if="mapsEmbedSrc" class="aspect-[4/3] overflow-hidden rounded-lg border border-border bg-muted">
                    <iframe
                        :src="mapsEmbedSrc"
                        class="size-full"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        :title="`Map of ${restaurant.name}`"
                    />
                </div>
            </div>
        </div>
    </section>
</template>
