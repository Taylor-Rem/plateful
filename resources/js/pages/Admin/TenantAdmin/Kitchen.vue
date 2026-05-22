<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    orders: App.Data.OrderData[];
}>();

const POLL_MS = 5000;

const now = ref(Date.now());
let nowTimer: ReturnType<typeof setInterval> | null = null;
let pollTimer: ReturnType<typeof setInterval> | null = null;
const advancing = ref<Set<number>>(new Set());

onMounted(() => {
    nowTimer = setInterval(() => {
        now.value = Date.now();
    }, 1000);
    pollTimer = setInterval(() => {
        router.reload({ only: ['orders'] });
    }, POLL_MS);
});

onBeforeUnmount(() => {
    if (nowTimer) clearInterval(nowTimer);
    if (pollTimer) clearInterval(pollTimer);
});

const columns = computed(() => [
    {
        key: 'confirmed',
        label: 'New',
        next: 'preparing',
        tone: 'border-sky-500/40 bg-sky-50 dark:bg-sky-950/40',
        chip: 'bg-sky-500 text-white',
        orders: props.orders.filter((o) => o.status === 'confirmed'),
    },
    {
        key: 'preparing',
        label: 'In the kitchen',
        next: 'ready',
        tone: 'border-amber-500/40 bg-amber-50 dark:bg-amber-950/40',
        chip: 'bg-amber-500 text-white',
        orders: props.orders.filter((o) => o.status === 'preparing'),
    },
    {
        key: 'ready',
        label: 'Ready',
        next: 'completed',
        tone: 'border-emerald-500/40 bg-emerald-50 dark:bg-emerald-950/40',
        chip: 'bg-emerald-500 text-white',
        orders: props.orders.filter((o) => o.status === 'ready'),
    },
]);

const advance = (order: App.Data.OrderData, toStatus: string): void => {
    if (advancing.value.has(order.id)) {
        return;
    }
    advancing.value.add(order.id);
    router.post(
        `/${props.restaurant.subdomain}/orders/${order.number}/transitions`,
        { to_status: toStatus },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['orders', 'flash'],
            onFinish: () => advancing.value.delete(order.id),
        },
    );
};

const elapsed = (placedAt: string | null): string => {
    if (!placedAt) {
        return '—';
    }
    const placed = new Date(placedAt).getTime();
    if (isNaN(placed)) {
        return '—';
    }
    const totalSeconds = Math.max(0, Math.floor((now.value - placed) / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    if (minutes < 1) {
        return 'just now';
    }
    if (minutes < 60) {
        return `${minutes}m`;
    }
    const hours = Math.floor(minutes / 60);
    const rem = minutes % 60;
    return `${hours}h ${rem}m`;
};

const elapsedTone = (placedAt: string | null, status: string): string => {
    if (!placedAt) {
        return 'text-muted-foreground';
    }
    const placed = new Date(placedAt).getTime();
    const minutes = (now.value - placed) / 60000;
    // Warn after 15min for new/preparing, after 5min for ready (sitting too long)
    const threshold = status === 'ready' ? 5 : 15;
    if (minutes >= threshold * 2) return 'text-destructive font-semibold';
    if (minutes >= threshold) return 'text-amber-600 dark:text-amber-400 font-semibold';
    return 'text-muted-foreground';
};

const orderTypeLabel = (type: string): string => {
    if (type === 'delivery') return 'Delivery';
    if (type === 'pickup') return 'Pickup';
    if (type === 'dine_in') return 'Dine in';
    return type;
};

const advanceLabel = (next: string): string => {
    if (next === 'preparing') return 'Start preparing';
    if (next === 'ready') return 'Mark ready';
    if (next === 'completed') return 'Mark completed';
    return 'Advance';
};
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="`${restaurant.name} Kitchen`" />

        <header class="border-b border-border bg-card">
            <div class="flex items-center justify-between px-6 py-3">
                <div class="flex items-center gap-3">
                    <Link
                        :href="`/${restaurant.subdomain}/dashboard`"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        ←
                    </Link>
                    <h1 class="text-xl font-semibold">{{ restaurant.name }} · Kitchen</h1>
                </div>
                <div class="flex items-center gap-3 text-xs text-muted-foreground">
                    <span>Auto-refreshing every {{ POLL_MS / 1000 }}s</span>
                </div>
            </div>
        </header>

        <main class="grid grid-cols-1 gap-4 p-4 md:grid-cols-3">
            <section
                v-for="col in columns"
                :key="col.key"
                class="flex flex-col rounded-xl border-2 p-4"
                :class="col.tone"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold">{{ col.label }}</h2>
                    <span
                        class="inline-flex h-6 min-w-6 items-center justify-center rounded-full px-2 text-sm font-bold"
                        :class="col.chip"
                    >
                        {{ col.orders.length }}
                    </span>
                </div>

                <div v-if="col.orders.length === 0" class="rounded-lg border border-dashed border-border bg-background/40 p-6 text-center text-sm text-muted-foreground">
                    Nothing here.
                </div>

                <div v-else class="flex flex-col gap-3">
                    <article
                        v-for="order in col.orders"
                        :key="order.id"
                        class="rounded-lg border border-border bg-card p-4 shadow-sm"
                    >
                        <header class="flex items-start justify-between gap-2">
                            <div>
                                <div class="text-2xl font-bold tracking-tight">#{{ order.number }}</div>
                                <div class="text-sm text-muted-foreground">
                                    {{ order.customerName || 'Guest' }} · {{ orderTypeLabel(order.type) }}
                                </div>
                            </div>
                            <div :class="['text-right text-sm', elapsedTone(order.placedAt, order.status)]">
                                {{ elapsed(order.placedAt) }}
                            </div>
                        </header>

                        <ul class="mt-3 space-y-1 text-sm">
                            <li
                                v-for="item in order.items"
                                :key="item.id"
                                class="leading-snug"
                            >
                                <span class="font-semibold">{{ item.quantity }}×</span>
                                {{ item.name }}
                                <span
                                    v-if="item.modifierSummary"
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ item.modifierSummary }}
                                </span>
                            </li>
                        </ul>

                        <p v-if="order.notes" class="mt-3 rounded-md bg-muted px-2 py-1.5 text-xs text-muted-foreground">
                            <strong class="text-foreground">Note:</strong> {{ order.notes }}
                        </p>

                        <button
                            type="button"
                            class="mt-4 w-full rounded-lg bg-primary px-4 py-3 text-base font-semibold text-primary-foreground transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="advancing.has(order.id)"
                            @click="advance(order, col.next)"
                        >
                            {{ advancing.has(order.id) ? 'Working…' : advanceLabel(col.next) }}
                        </button>
                    </article>
                </div>
            </section>
        </main>
    </div>
</template>
