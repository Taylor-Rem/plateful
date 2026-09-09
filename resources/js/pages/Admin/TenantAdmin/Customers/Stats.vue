<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/admin/EmptyState.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import StatCard from '@/components/admin/StatCard.vue';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import { formatCents, formatRelativeTime } from '@/lib/orderStatus';
import CustomersTabs from './CustomersTabs.vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    stats: App.Data.CustomerStatsData;
    topCustomers: App.Data.CustomerData[];
}>();

const hasOrders = computed(
    () =>
        props.stats.identifiedOrders > 0 ||
        props.stats.monthly.some(
            (m) => m.newCents + m.returningCents + m.guestCents > 0,
        ) ||
        props.topCustomers.length > 0,
);

const maxMonthCents = computed(() =>
    Math.max(
        1,
        ...props.stats.monthly.map(
            (m) => m.newCents + m.returningCents + m.guestCents,
        ),
    ),
);

const MONTH_NAMES = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

function monthLabel(month: string): string {
    return MONTH_NAMES[Number(month.split('-')[1]) - 1] ?? month;
}

function barHeight(cents: number): string {
    return `${(cents / maxMonthCents.value) * 100}%`;
}

function monthTitle(m: App.Data.CustomerStatsMonthData): string {
    return `${monthLabel(m.month)} ${m.month.split('-')[0]} — new: ${formatCents(m.newCents)}, returning: ${formatCents(m.returningCents)}, guest: ${formatCents(m.guestCents)}`;
}

const SERIES: {
    key: 'returningCents' | 'newCents' | 'guestCents';
    label: string;
    swatch: string;
}[] = [
    { key: 'returningCents', label: 'Returning', swatch: 'bg-chart-1' },
    { key: 'newCents', label: 'New', swatch: 'bg-chart-4' },
    { key: 'guestCents', label: 'Guest', swatch: 'bg-muted-foreground/30' },
];

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
        <Head title="Customer stats" />

        <PageHeader
            title="Customers"
            description="How much of your online revenue comes from regulars — the customers you own, coming back."
        />

        <CustomersTabs :subdomain="restaurant.subdomain" active="stats" />

        <EmptyState
            v-if="!hasOrders"
            class="mt-6"
            title="No orders yet"
            description="Once orders come in, you'll see how much of your revenue comes from regulars — repeat rate, month-by-month new vs. returning revenue, and your top customers."
        />

        <template v-else>
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Repeat revenue"
                    :value="
                        stats.repeatRevenuePct !== null
                            ? `${stats.repeatRevenuePct}%`
                            : null
                    "
                    hint="Share of signed-in revenue from repeat orders"
                />
                <StatCard
                    label="Repeat orders"
                    :value="
                        stats.repeatOrderPct !== null
                            ? `${stats.repeatOrderPct}%`
                            : null
                    "
                    hint="Share of signed-in orders that are repeats"
                />
                <StatCard
                    label="Avg orders per customer"
                    :value="
                        stats.avgOrdersPerCustomer !== null
                            ? stats.avgOrdersPerCustomer.toFixed(1)
                            : null
                    "
                    :hint="`Across ${stats.identifiedCustomers} signed-in ${stats.identifiedCustomers === 1 ? 'customer' : 'customers'}`"
                />
                <StatCard
                    label="Median days between orders"
                    :value="
                        stats.medianDaysBetweenOrders !== null
                            ? stats.medianDaysBetweenOrders.toFixed(1)
                            : null
                    "
                    hint="Typical gap between a customer's orders"
                />
            </div>

            <div
                class="mt-6 rounded-lg border border-border bg-card p-4 sm:p-6"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <div>
                        <h2 class="text-sm font-medium text-foreground">
                            New vs. returning revenue
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Last 12 months
                        </p>
                    </div>
                    <div
                        class="flex items-center gap-4 text-xs text-muted-foreground"
                    >
                        <span
                            v-for="series in SERIES"
                            :key="series.key"
                            class="inline-flex items-center gap-1.5"
                        >
                            <span
                                class="size-2.5 rounded-sm"
                                :class="series.swatch"
                            />
                            {{ series.label }}
                        </span>
                    </div>
                </div>

                <div class="mt-4 flex gap-3" aria-hidden="true">
                    <div
                        class="flex h-48 w-14 shrink-0 flex-col justify-between text-right text-[10px] text-muted-foreground tabular-nums"
                    >
                        <span>{{ formatCents(maxMonthCents) }}</span>
                        <span>{{
                            formatCents(Math.round(maxMonthCents / 2))
                        }}</span>
                        <span>$0</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex h-48 items-end gap-1.5 border-b border-border sm:gap-2.5"
                        >
                            <div
                                v-for="m in stats.monthly"
                                :key="m.month"
                                class="flex h-full flex-1 flex-col justify-end overflow-hidden rounded-t-sm"
                                :title="monthTitle(m)"
                            >
                                <div
                                    class="bg-muted-foreground/30"
                                    :style="{ height: barHeight(m.guestCents) }"
                                />
                                <div
                                    class="bg-chart-4"
                                    :style="{ height: barHeight(m.newCents) }"
                                />
                                <div
                                    class="bg-chart-1"
                                    :style="{
                                        height: barHeight(m.returningCents),
                                    }"
                                />
                            </div>
                        </div>
                        <div class="mt-1 flex gap-1.5 sm:gap-2.5">
                            <span
                                v-for="m in stats.monthly"
                                :key="m.month"
                                class="flex-1 text-center text-[10px] text-muted-foreground"
                            >
                                {{ monthLabel(m.month) }}
                            </span>
                        </div>
                    </div>
                </div>

                <table class="sr-only">
                    <caption>
                        Monthly revenue, last 12 months
                    </caption>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>New customer revenue</th>
                            <th>Returning customer revenue</th>
                            <th>Guest revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in stats.monthly" :key="m.month">
                            <td>{{ m.month }}</td>
                            <td>{{ formatCents(m.newCents) }}</td>
                            <td>{{ formatCents(m.returningCents) }}</td>
                            <td>{{ formatCents(m.guestCents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                class="mt-6 overflow-hidden rounded-lg border border-border bg-card"
            >
                <div
                    class="border-b border-border px-4 py-3 text-sm font-medium text-foreground"
                >
                    Top customers
                </div>
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Orders</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Lifetime spend
                            </th>
                            <th
                                class="hidden px-4 py-3 font-medium sm:table-cell"
                            >
                                Last order
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-if="topCustomers.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-12 text-center text-sm text-muted-foreground"
                            >
                                No signed-in customers yet — guest orders don't
                                appear here.
                            </td>
                        </tr>
                        <tr
                            v-for="customer in topCustomers"
                            :key="customer.id"
                            class="transition hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <span
                                    class="block font-medium text-foreground"
                                    >{{ customer.name }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ customer.email }}</span
                                >
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
                            <td
                                class="hidden px-4 py-3 text-xs text-muted-foreground sm:table-cell"
                                :title="formatDate(customer.lastOrderedAt)"
                            >
                                {{ formatRelativeTime(customer.lastOrderedAt) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p class="mt-4 text-xs text-muted-foreground">
            Online orders only. Repeat metrics cover signed-in customers; guest
            orders shown separately.
        </p>
    </div>
</template>
