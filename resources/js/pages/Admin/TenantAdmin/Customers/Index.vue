<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Download, MailCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import { Input } from '@/components/ui/input';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import { formatCents, formatRelativeTime } from '@/lib/orderStatus';
import {
    exportMethod as customersExport,
    index as customersIndex,
} from '@/routes/admin/restaurant/customers';

type OrderedFilter = 30 | 90 | null;
type SortKey =
    | 'name'
    | 'total_orders'
    | 'total_spent'
    | 'first_ordered'
    | 'last_ordered';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    customers: App.Data.CustomerData[];
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        search: string;
        ordered: OrderedFilter;
        marketing: 'opted_in' | null;
        sort: SortKey;
        dir: 'asc' | 'desc';
    };
    stats: {
        totalCustomers: number;
        optedInCount: number;
    };
}>();

const search = ref<string>(props.filters.search ?? '');

type FilterPill = {
    key: string;
    label: string;
    ordered: OrderedFilter;
    marketing: 'opted_in' | null;
};

const pills: FilterPill[] = [
    { key: 'all', label: 'All', ordered: null, marketing: null },
    { key: '30', label: 'Ordered last 30 days', ordered: 30, marketing: null },
    { key: '90', label: 'Ordered last 90 days', ordered: 90, marketing: null },
    {
        key: 'opted_in',
        label: 'Marketing opted-in',
        ordered: null,
        marketing: 'opted_in',
    },
];

const activePill = computed<string>(() => {
    if (props.filters.marketing === 'opted_in') {
        return 'opted_in';
    }

    if (props.filters.ordered === 30) {
        return '30';
    }

    if (props.filters.ordered === 90) {
        return '90';
    }

    return 'all';
});

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (props.filters.search) {
        query.search = props.filters.search;
    }

    if (props.filters.ordered) {
        query.ordered = String(props.filters.ordered);
    }

    if (props.filters.marketing) {
        query.marketing = props.filters.marketing;
    }

    if (props.filters.sort !== 'last_ordered' || props.filters.dir !== 'desc') {
        query.sort = props.filters.sort;
        query.dir = props.filters.dir;
    }

    return query;
}

function visitWithFilters(overrides: Record<string, string | null>): void {
    const query = currentQuery();
    delete query.page;

    for (const [key, value] of Object.entries(overrides)) {
        if (value === null) {
            delete query[key];
        } else {
            query[key] = value;
        }
    }

    router.get(customersIndex.url(props.restaurant.subdomain), query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function selectPill(pill: FilterPill): void {
    visitWithFilters({
        ordered: pill.ordered ? String(pill.ordered) : null,
        marketing: pill.marketing,
    });
}

let searchTimer: number | undefined;
watch(search, (val) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        visitWithFilters({ search: val || null });
    }, 300);
});

const SORT_COLUMNS: { key: SortKey; label: string }[] = [
    { key: 'name', label: 'Customer' },
    { key: 'total_orders', label: 'Orders' },
    { key: 'total_spent', label: 'Lifetime spend' },
    { key: 'first_ordered', label: 'First order' },
    { key: 'last_ordered', label: 'Last order' },
];

function sortBy(key: SortKey): void {
    const dir =
        props.filters.sort === key && props.filters.dir === 'desc'
            ? 'asc'
            : 'desc';
    visitWithFilters({ sort: key, dir });
}

function goToPage(page: number): void {
    const query = currentQuery();

    if (page > 1) {
        query.page = String(page);
    }

    router.get(customersIndex.url(props.restaurant.subdomain), query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

const exportUrl = computed(() =>
    customersExport.url(props.restaurant.subdomain, {
        query: currentQuery(),
    }),
);

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="Customers" />

        <PageHeader
            title="Customers"
            description="Everyone who has ordered online — your list, not a platform's. Phone and walk-in customers aren't included."
        >
            <template #actions>
                <a
                    :href="exportUrl"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                >
                    <Download class="size-4" />
                    Export CSV
                </a>
            </template>
        </PageHeader>

        <div class="mt-4 flex flex-wrap items-center gap-4 text-sm">
            <span class="text-muted-foreground">
                <span class="font-semibold text-foreground tabular-nums">{{
                    stats.totalCustomers
                }}</span>
                {{ stats.totalCustomers === 1 ? 'customer' : 'customers' }}
            </span>
            <span
                class="inline-flex items-center gap-1.5 text-muted-foreground"
            >
                <MailCheck class="size-4 text-emerald-600" />
                <span class="font-semibold text-foreground tabular-nums">{{
                    stats.optedInCount
                }}</span>
                opted into marketing
            </span>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-2">
            <button
                v-for="pill in pills"
                :key="pill.key"
                type="button"
                class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                :class="
                    activePill === pill.key
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground hover:bg-muted/80'
                "
                @click="selectPill(pill)"
            >
                {{ pill.label }}
            </button>
        </div>

        <div class="mt-4 max-w-sm">
            <Input
                v-model="search"
                type="search"
                placeholder="Search by name or email..."
            />
        </div>

        <div
            class="mt-6 overflow-hidden rounded-lg border border-border bg-card"
        >
            <div class="divide-y divide-border sm:hidden">
                <p
                    v-if="customers.length === 0"
                    class="px-4 py-12 text-center text-sm text-muted-foreground"
                >
                    No customers match your filters yet. Customers appear here
                    once they place an online order.
                </p>
                <div
                    v-for="customer in customers"
                    :key="customer.id"
                    class="flex flex-col gap-1.5 px-4 py-3"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="truncate font-medium text-foreground">{{
                            customer.name
                        }}</span>
                        <span class="text-sm text-foreground tabular-nums">{{
                            formatCents(customer.totalSpentCents)
                        }}</span>
                    </span>
                    <span class="truncate text-xs text-muted-foreground">{{
                        customer.email
                    }}</span>
                    <span
                        class="flex items-center gap-3 text-xs text-muted-foreground"
                    >
                        <span class="tabular-nums"
                            >{{ customer.totalOrders }}
                            {{
                                customer.totalOrders === 1 ? 'order' : 'orders'
                            }}</span
                        >
                        <span>{{
                            formatRelativeTime(customer.lastOrderedAt)
                        }}</span>
                        <span
                            v-if="customer.marketingOptedIn"
                            class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                        >
                            <MailCheck class="size-3" />
                            Marketing
                        </span>
                    </span>
                </div>
            </div>
            <table class="hidden w-full text-sm sm:table">
                <thead
                    class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                >
                    <tr>
                        <th
                            v-for="column in SORT_COLUMNS"
                            :key="column.key"
                            class="px-4 py-3 font-medium"
                            :class="{
                                'text-right': column.key === 'total_spent',
                            }"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 uppercase transition hover:text-foreground"
                                @click="sortBy(column.key)"
                            >
                                {{ column.label }}
                                <ArrowDown
                                    v-if="
                                        filters.sort === column.key &&
                                        filters.dir === 'desc'
                                    "
                                    class="size-3"
                                />
                                <ArrowUp
                                    v-else-if="filters.sort === column.key"
                                    class="size-3"
                                />
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">Loyalty</th>
                        <th class="px-4 py-3 font-medium">Marketing</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-if="customers.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-12 text-center text-sm text-muted-foreground"
                        >
                            No customers match your filters yet. Customers
                            appear here once they place an online order.
                        </td>
                    </tr>
                    <tr
                        v-for="customer in customers"
                        :key="customer.id"
                        class="transition hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <span class="block font-medium text-foreground">{{
                                customer.name
                            }}</span>
                            <span class="block text-xs text-muted-foreground">{{
                                customer.email
                            }}</span>
                        </td>
                        <td
                            class="px-4 py-3 text-muted-foreground tabular-nums"
                        >
                            {{ customer.totalOrders }}
                        </td>
                        <td
                            class="px-4 py-3 text-right text-foreground tabular-nums"
                        >
                            {{ formatCents(customer.totalSpentCents) }}
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ formatDate(customer.firstOrderedAt) }}
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ formatRelativeTime(customer.lastOrderedAt) }}
                        </td>
                        <td
                            class="px-4 py-3 text-muted-foreground tabular-nums"
                        >
                            {{ customer.loyaltyPoints }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="customer.marketingOptedIn"
                                class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                            >
                                <MailCheck class="size-3" />
                                Opted in
                            </span>
                            <span
                                v-else
                                class="text-xs text-muted-foreground/60"
                                >—</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pagination.lastPage > 1"
            class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground"
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
    </div>
</template>
