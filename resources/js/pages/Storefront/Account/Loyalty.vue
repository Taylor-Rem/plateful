<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';
import AccountTabs from '@/pages/Storefront/Account/AccountTabs.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
    balance: number;
    pointsPerDollar: number;
    recentOrders: App.Data.OrderData[];
}>();

const formatDate = (iso: string | null): string => {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};
</script>

<template>
    <div>
        <Head title="Loyalty" />

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <h1
                class="mb-1 text-2xl font-bold tracking-tight"
                :style="{ color: 'var(--brand-primary)' }"
            >
                Loyalty
            </h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Earn rewards at {{ restaurant.name }}.
            </p>

            <AccountTabs active="loyalty" />

            <div
                class="rounded-lg border border-border p-8"
                :style="{
                    backgroundColor: 'var(--brand-primary)',
                    color: 'var(--brand-primary-foreground)',
                }"
            >
                <div class="flex items-center gap-3">
                    <Sparkles class="size-6" />
                    <span
                        class="text-sm font-medium tracking-wide uppercase opacity-90"
                    >
                        Your balance
                    </span>
                </div>
                <p class="mt-3 text-5xl font-bold tabular-nums">
                    {{ balance }}
                    <span class="text-2xl font-normal opacity-80">points</span>
                </p>
                <p class="mt-2 text-sm opacity-90">
                    Earn {{ pointsPerDollar }} point per $1 spent at
                    {{ restaurant.name }}.
                </p>
            </div>

            <section class="mt-8">
                <h2 class="mb-3 text-base font-semibold">Recent earnings</h2>
                <div
                    v-if="recentOrders.length === 0"
                    class="rounded-lg border border-dashed border-border bg-card p-8 text-center text-sm text-muted-foreground"
                >
                    No completed orders yet. Points are awarded once your order
                    is marked completed.
                </div>
                <ul
                    v-else
                    class="divide-y divide-border rounded-lg border border-border bg-card"
                >
                    <li
                        v-for="o in recentOrders"
                        :key="o.id"
                        class="flex items-center justify-between gap-4 p-4"
                    >
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/account/orders/${o.number}`"
                                class="font-mono text-sm font-semibold hover:underline"
                                :style="{ color: 'var(--brand-primary)' }"
                            >
                                {{ o.number }}
                            </Link>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ formatDate(o.placedAt) }}
                            </p>
                        </div>
                        <span
                            class="text-sm font-semibold"
                            :style="{ color: 'var(--brand-primary)' }"
                        >
                            +{{ o.awardedLoyaltyPoints }} pts
                        </span>
                    </li>
                </ul>
            </section>
        </main>
    </div>
</template>
