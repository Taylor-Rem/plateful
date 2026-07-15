<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { Bike, ShoppingBag } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import {
    ORDER_STATUSES,
    ORDER_STATUS_LABELS,
    formatCents,
    formatRelativeTime,
    statusBadgeClasses,
} from '@/lib/orderStatus';
import type { OrderStatusValue } from '@/lib/orderStatus';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    orders: App.Data.OrderData[];
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        status: OrderStatusValue[];
        search: string;
    };
    statusCounts: Record<OrderStatusValue, number>;
}>();

const search = ref<string>(props.filters.search ?? '');
const polling = ref<boolean>(true);

usePoll(15000, {
    // reload() forces preserveScroll/preserveState to true, so they aren't
    // part of ReloadOptions and passing them here was a no-op.
    only: ['orders', 'pagination', 'statusCounts'],
    onStart: () => (polling.value = true),
    onFinish: () => (polling.value = true),
});

const totalCount = computed(() =>
    ORDER_STATUSES.reduce((sum, s) => sum + (props.statusCounts[s] ?? 0), 0),
);

const activeStatus = computed<OrderStatusValue | 'all'>(() => {
    if (!props.filters.status || props.filters.status.length === 0) {
        return 'all';
    }

    if (props.filters.status.length === 1) {
        return props.filters.status[0]!;
    }

    return 'all';
});

function visitWithFilters(params: {
    status?: OrderStatusValue | null;
    search?: string | null;
    page?: number;
}): void {
    const query: Record<string, string> = {};
    const nextStatus =
        params.status === undefined
            ? (props.filters.status[0] ?? null)
            : params.status;
    const nextSearch =
        params.search === undefined
            ? (props.filters.search ?? '')
            : (params.search ?? '');

    if (nextStatus) {
        query.status = nextStatus;
    }

    if (nextSearch) {
        query.search = nextSearch;
    }

    if (params.page && params.page > 1) {
        query.page = String(params.page);
    }

    router.get(`/${props.restaurant.subdomain}/orders`, query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function selectStatus(status: OrderStatusValue | 'all'): void {
    visitWithFilters({ status: status === 'all' ? null : status, page: 1 });
}

let searchTimer: number | undefined;
watch(search, (val) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        visitWithFilters({ search: val ?? '', page: 1 });
    }, 300);
});

function goToPage(page: number): void {
    visitWithFilters({ page });
}
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Orders`" />

        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h2 class="text-2xl font-semibold text-foreground">Orders</h2>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                >
                    <span class="relative inline-flex h-2 w-2">
                        <span
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"
                            :class="{ 'opacity-0': !polling }"
                        />
                        <span
                            class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"
                        />
                    </span>
                    Live
                </span>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                :class="
                    activeStatus === 'all'
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground hover:bg-muted/80'
                "
                @click="selectStatus('all')"
            >
                All
                <span
                    class="ml-1 rounded bg-background/40 px-1 text-[10px] tabular-nums"
                    >{{ totalCount }}</span
                >
            </button>
            <button
                v-for="status in ORDER_STATUSES"
                :key="status"
                type="button"
                class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                :class="
                    activeStatus === status
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground hover:bg-muted/80'
                "
                @click="selectStatus(status)"
            >
                {{ ORDER_STATUS_LABELS[status] }}
                <span
                    class="ml-1 rounded bg-background/40 px-1 text-[10px] tabular-nums"
                    >{{ statusCounts[status] ?? 0 }}</span
                >
            </button>
        </div>

        <div class="mt-4 max-w-sm">
            <Input
                v-model="search"
                type="search"
                placeholder="Search by order number or customer..."
            />
        </div>

        <div
            class="mt-6 overflow-hidden rounded-lg border border-border bg-card"
        >
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">#</th>
                        <th class="px-4 py-3 font-medium">Customer</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Placed</th>
                        <th class="px-4 py-3 text-right font-medium">Total</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-if="orders.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-12 text-center text-sm text-muted-foreground"
                        >
                            No orders match your filters yet. Orders will appear
                            here as customers place them.
                        </td>
                    </tr>
                    <tr
                        v-for="order in orders"
                        :key="order.id"
                        class="cursor-pointer transition hover:bg-muted/30"
                        @click="
                            router.visit(
                                `/${restaurant.subdomain}/orders/${order.number}`,
                            )
                        "
                    >
                        <td class="px-4 py-3 font-mono text-xs text-foreground">
                            {{ order.number }}
                        </td>
                        <td class="px-4 py-3 text-foreground">
                            {{ order.customerName }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span
                                class="inline-flex items-center gap-1.5 text-xs"
                            >
                                <ShoppingBag
                                    v-if="order.type === 'pickup'"
                                    class="size-3.5"
                                />
                                <Bike v-else class="size-3.5" />
                                {{
                                    order.type === 'pickup'
                                        ? 'Pickup'
                                        : 'Delivery'
                                }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ formatRelativeTime(order.placedAt) }}
                        </td>
                        <td
                            class="px-4 py-3 text-right text-foreground tabular-nums"
                        >
                            {{ formatCents(order.totalCents) }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                :class="statusBadgeClasses(order.status)"
                            >
                                {{ order.status }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pagination.lastPage > 1"
            class="mt-4 flex items-center justify-between text-sm text-muted-foreground"
        >
            <span
                >Showing {{ pagination.from ?? 0 }}–{{ pagination.to ?? 0 }} of
                {{ pagination.total }}</span
            >
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-md border border-border bg-card px-3 py-1.5 text-xs disabled:opacity-50"
                    :disabled="pagination.currentPage <= 1"
                    @click="goToPage(pagination.currentPage - 1)"
                >
                    Previous
                </button>
                <span class="text-xs tabular-nums"
                    >Page {{ pagination.currentPage }} of
                    {{ pagination.lastPage }}</span
                >
                <button
                    type="button"
                    class="rounded-md border border-border bg-card px-3 py-1.5 text-xs disabled:opacity-50"
                    :disabled="pagination.currentPage >= pagination.lastPage"
                    @click="goToPage(pagination.currentPage + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </TenantAdminLayout>
</template>
