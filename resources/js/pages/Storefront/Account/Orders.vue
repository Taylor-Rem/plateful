<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Bike, ShoppingBag } from 'lucide-vue-next';
import AccountTabs from '@/pages/Storefront/Account/AccountTabs.vue';

type Paginated<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
};

defineProps<{
    restaurant: App.Data.RestaurantData;
    orders: Paginated<App.Data.OrderData>;
}>();

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

const formatDate = (iso: string | null): string => {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
};

const statusColor = (s: string): string => {
    switch (s) {
        case 'completed':
            return 'bg-emerald-100 text-emerald-800';
        case 'cancelled':
            return 'bg-red-100 text-red-800';
        case 'ready':
            return 'bg-blue-100 text-blue-800';
        case 'preparing':
            return 'bg-amber-100 text-amber-800';
        case 'confirmed':
            return 'bg-indigo-100 text-indigo-800';
        default:
            return 'bg-muted text-foreground';
    }
};
</script>

<template>
    <div>
        <Head title="Order history" />

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <h1
                class="mb-1 text-2xl font-bold tracking-tight"
                :style="{ color: 'var(--brand-primary)' }"
            >
                Order history
            </h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Your past orders at {{ restaurant.name }}.
            </p>

            <AccountTabs active="orders" />

            <div
                v-if="orders.data.length === 0"
                class="rounded-lg border border-dashed border-border bg-card p-10 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    You haven't placed any orders yet.
                </p>
                <Link
                    href="/"
                    class="mt-4 inline-block rounded-md px-4 py-2 text-sm font-medium"
                    :style="{
                        backgroundColor: 'var(--brand-primary)',
                        color: 'var(--brand-primary-foreground)',
                    }"
                >
                    Browse menu
                </Link>
            </div>

            <ul v-else class="space-y-3">
                <li
                    v-for="order in orders.data"
                    :key="order.id"
                    class="rounded-lg border border-border bg-card p-4 transition hover:shadow-sm"
                >
                    <Link
                        :href="`/account/orders/${order.number}`"
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 text-sm">
                                <component
                                    :is="
                                        order.type === 'delivery'
                                            ? Bike
                                            : ShoppingBag
                                    "
                                    class="size-4 text-muted-foreground"
                                />
                                <span
                                    class="font-mono text-sm font-semibold"
                                    :style="{ color: 'var(--brand-primary)' }"
                                >
                                    {{ order.number }}
                                </span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                                    :class="statusColor(order.status)"
                                >
                                    {{ order.status }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ formatDate(order.placedAt) }}
                            </p>
                            <p
                                v-if="order.awardedLoyaltyPoints > 0"
                                class="mt-1 text-xs"
                                :style="{ color: 'var(--brand-primary)' }"
                            >
                                +{{ order.awardedLoyaltyPoints }} loyalty points
                            </p>
                        </div>
                        <div
                            class="text-right text-sm font-semibold tabular-nums"
                        >
                            {{ formatPrice(order.totalCents) }}
                        </div>
                    </Link>
                </li>
            </ul>

            <nav
                v-if="orders.last_page > 1"
                class="mt-6 flex flex-wrap gap-1"
                aria-label="Pagination"
            >
                <Link
                    v-for="(link, i) in orders.links"
                    :key="i"
                    :href="link.url ?? ''"
                    class="rounded-md border border-border px-3 py-1.5 text-xs"
                    :class="
                        link.active
                            ? 'text-white'
                            : link.url
                              ? 'text-foreground hover:bg-muted'
                              : 'pointer-events-none text-muted-foreground/40'
                    "
                    :style="
                        link.active
                            ? {
                                  backgroundColor: 'var(--brand-primary)',
                                  borderColor: 'var(--brand-primary)',
                                  color: 'var(--brand-primary-foreground)',
                              }
                            : {}
                    "
                >
                    <!-- Paginator labels carry entities (&laquo;, &raquo;), so
                         they render as HTML — on a span, not on the Link. -->
                    <span v-html="link.label" />
                </Link>
            </nav>
        </main>
    </div>
</template>
