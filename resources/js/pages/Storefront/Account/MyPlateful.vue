<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Store } from 'lucide-vue-next';
import AccountTabs from '@/pages/Storefront/Account/AccountTabs.vue';

interface PlatefulRestaurant {
    id: number;
    name: string;
    subdomain: string;
    logoUrl: string | null;
    publicUrl: string;
    totalOrders: number;
    totalSpentCents: number;
    firstOrderedAt: string | null;
    lastOrderedAt: string | null;
}

defineProps<{
    restaurants: PlatefulRestaurant[];
}>();

const formatDate = (iso: string | null): string => {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

const formatMoney = (cents: number): string => {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
    }).format(cents / 100);
};
</script>

<template>
    <div>
        <Head title="My Plateful" />

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <h1
                class="mb-1 text-2xl font-bold tracking-tight"
                :style="{ color: 'var(--brand-primary)' }"
            >
                My Plateful
            </h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Every restaurant you've connected with on Plateful.
            </p>

            <AccountTabs active="myPlateful" />

            <div
                v-if="restaurants.length === 0"
                class="rounded-lg border border-dashed border-border bg-card p-8 text-center text-sm text-muted-foreground"
            >
                You haven't ordered from any other Plateful restaurants yet.
            </div>

            <ul v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <li
                    v-for="r in restaurants"
                    :key="r.id"
                    class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4"
                    :data-test="`my-plateful-entry-${r.subdomain}`"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted"
                        >
                            <img
                                v-if="r.logoUrl"
                                :src="r.logoUrl"
                                :alt="r.name"
                                class="size-full object-cover"
                            />
                            <Store
                                v-else
                                class="size-6 text-muted-foreground"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <a
                                :href="r.publicUrl"
                                class="block truncate text-base font-semibold hover:underline"
                            >
                                {{ r.name }}
                            </a>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ r.subdomain }}.plateful
                            </p>
                        </div>
                    </div>

                    <dl class="grid grid-cols-3 gap-2 text-xs">
                        <div>
                            <dt class="text-muted-foreground">Orders</dt>
                            <dd class="font-medium tabular-nums">
                                {{ r.totalOrders }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Spent</dt>
                            <dd class="font-medium tabular-nums">
                                {{ formatMoney(r.totalSpentCents) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Last order</dt>
                            <dd class="font-medium">
                                {{ formatDate(r.lastOrderedAt) }}
                            </dd>
                        </div>
                    </dl>
                </li>
            </ul>
        </main>
    </div>
</template>
