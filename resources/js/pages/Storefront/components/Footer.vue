<script setup lang="ts">
import { computed } from 'vue';
import { Clock, Facebook, Globe, Instagram, MapPin, Music2, Pencil, Phone, Twitter, Youtube } from 'lucide-vue-next';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    editMode: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit-social'): void;
}>();

const SOCIAL_META: Record<string, { label: string; icon: typeof Instagram }> = {
    instagram: { label: 'Instagram', icon: Instagram },
    facebook: { label: 'Facebook', icon: Facebook },
    twitter: { label: 'Twitter / X', icon: Twitter },
    tiktok: { label: 'TikTok', icon: Music2 },
    youtube: { label: 'YouTube', icon: Youtube },
    website: { label: 'Website', icon: Globe },
};

const socialEntries = computed(() =>
    Object.entries(props.restaurant.socialLinks ?? {})
        .filter(([key]) => key in SOCIAL_META)
        .map(([key, url]) => ({ key, url, ...SOCIAL_META[key] })),
);

const addressLine = computed(() => {
    const r = props.restaurant;
    const line1 = [r.street, r.street2].filter(Boolean).join(' ');
    const line2 = [r.city, r.state].filter(Boolean).join(', ');
    return [line1, line2, r.postalCode].filter((p): p is string => Boolean(p && p.trim())).join(', ');
});

const telHref = computed(() => {
    const raw = props.restaurant.phone?.replace(/[^0-9+]/g, '') ?? '';
    return raw ? `tel:${raw}` : null;
});

const year = new Date().getFullYear();
</script>

<template>
    <footer class="border-t border-border bg-card text-foreground">
        <div class="mx-auto grid max-w-5xl gap-8 px-6 py-10 sm:grid-cols-2 md:grid-cols-3">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-foreground/70">{{ restaurant.name }}</h3>
                <div class="mt-3 space-y-2 text-sm">
                    <p v-if="addressLine" class="flex items-start gap-2">
                        <MapPin class="mt-0.5 size-4 shrink-0 text-foreground/60" />
                        <span>{{ addressLine }}</span>
                    </p>
                    <p v-if="telHref" class="flex items-center gap-2">
                        <Phone class="size-4 shrink-0 text-foreground/60" />
                        <a :href="telHref" class="hover:underline">{{ restaurant.phone }}</a>
                    </p>
                    <p v-if="restaurant.openStatusLabel" class="flex items-center gap-2">
                        <Clock class="size-4 shrink-0 text-foreground/60" />
                        <span>{{ restaurant.openStatusLabel }}</span>
                    </p>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-foreground/70">Explore</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="#menu" class="hover:underline">Menu</a></li>
                    <li><a href="#about" class="hover:underline">About</a></li>
                    <li><a href="#gallery" class="hover:underline">Photos</a></li>
                    <li><a href="#location" class="hover:underline">Visit us</a></li>
                </ul>
            </div>

            <div>
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-foreground/70">Follow</h3>
                    <button
                        v-if="editMode"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-1 text-xs font-medium text-foreground hover:bg-muted/70"
                        @click="emit('edit-social')"
                    >
                        <Pencil class="size-3.5" /> Edit
                    </button>
                </div>
                <ul v-if="socialEntries.length > 0" class="mt-3 flex flex-wrap gap-2">
                    <li v-for="entry in socialEntries" :key="entry.key">
                        <a
                            :href="entry.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex size-9 items-center justify-center rounded-full bg-muted text-foreground transition hover:bg-foreground hover:text-background"
                            :aria-label="entry.label"
                        >
                            <component :is="entry.icon" class="size-4" />
                        </a>
                    </li>
                </ul>
                <p v-else-if="editMode" class="mt-3 text-xs text-muted-foreground">
                    No social links yet. Click Edit to add them.
                </p>
            </div>
        </div>

        <div class="border-t border-border">
            <div class="mx-auto flex max-w-5xl flex-col items-start justify-between gap-2 px-6 py-4 text-xs text-foreground/60 sm:flex-row sm:items-center">
                <p>© {{ year }} {{ restaurant.name }}. All rights reserved.</p>
                <p>
                    Powered by
                    <a href="https://plateful.fyi" target="_blank" rel="noopener" class="font-medium hover:underline">Plateful</a>
                </p>
            </div>
        </div>
    </footer>
</template>
