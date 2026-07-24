<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardCheck, ExternalLink } from 'lucide-vue-next';
import EmptyState from '@/components/admin/EmptyState.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SectionCard from '@/components/admin/SectionCard.vue';
import StatCard from '@/components/admin/StatCard.vue';
import { Button } from '@/components/ui/button';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import {
    formatCents,
    formatRelativeTime,
    statusBadgeClasses,
} from '@/lib/orderStatus';
import { show as onboardingShow } from '@/routes/admin/restaurant/onboarding';
import { show as ordersShow } from '@/routes/admin/restaurant/orders';

type SetupStep = {
    key: string;
    title: string;
    description: string;
    complete: boolean;
    required: boolean;
};

defineProps<{
    restaurant: App.Data.RestaurantData;
    stats: App.Data.DashboardStatsData;
    recentOrders: App.Data.OrderSummaryData[];
    setup: { canGoLive: boolean; remaining: SetupStep[] } | null;
}>();

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="Dashboard" />
        <PageHeader title="Dashboard">
            <template #actions>
                <Button as-child variant="outline">
                    <a
                        :href="restaurant.publicUrl"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2"
                    >
                        Visit storefront <ExternalLink class="size-4" />
                    </a>
                </Button>
            </template>
        </PageHeader>

        <SectionCard
            v-if="setup"
            class="mt-6 border-amber-300 dark:border-amber-700"
            title="Finish setting up"
            :description="
                setup.canGoLive
                    ? 'Everything required is done — you can go live.'
                    : 'A few steps left before you can take orders.'
            "
        >
            <ul v-if="setup.remaining.length > 0" class="space-y-2 text-sm">
                <li
                    v-for="step in setup.remaining"
                    :key="step.key"
                    class="flex items-start gap-2"
                >
                    <ClipboardCheck
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span>
                        <span class="font-medium text-foreground">{{
                            step.title
                        }}</span>
                        <span v-if="step.required" class="text-amber-600">
                            (required)</span
                        >
                        <span class="block text-muted-foreground">{{
                            step.description
                        }}</span>
                    </span>
                </li>
            </ul>
            <Button as-child class="mt-4">
                <Link :href="onboardingShow.url(restaurant.subdomain)">
                    {{ setup.canGoLive ? 'Go live' : 'Finish setup' }}
                </Link>
            </Button>
        </SectionCard>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Orders today" :value="stats.ordersToday" />
            <StatCard
                label="Revenue today"
                :value="formatCents(stats.revenueTodayCents)"
                hint="Captured payments, net of refunds"
            />
            <StatCard
                label="Avg ticket"
                :value="
                    stats.avgTicketCents !== null
                        ? formatCents(stats.avgTicketCents)
                        : null
                "
            />
            <StatCard
                label="Pending"
                :value="stats.pendingCount"
                hint="Awaiting confirmation — all days"
            />
        </div>

        <SectionCard class="mt-6" title="Recent orders">
            <EmptyState
                v-if="recentOrders.length === 0"
                title="No orders yet"
                description="Orders will appear here as customers place them."
            />
            <ul v-else class="divide-y divide-border text-sm">
                <li v-for="order in recentOrders" :key="order.id">
                    <Link
                        :href="
                            ordersShow.url({
                                restaurant: restaurant.subdomain,
                                order: order.number,
                            })
                        "
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0 hover:bg-muted/30"
                    >
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-xs text-foreground">{{
                                order.number
                            }}</span>
                            <span class="text-foreground">{{
                                order.customerName
                            }}</span>
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                :class="statusBadgeClasses(order.status)"
                            >
                                {{ order.status }}
                            </span>
                        </div>
                        <div
                            class="flex items-center gap-3 text-muted-foreground"
                        >
                            <span v-if="order.placedAt">{{
                                formatRelativeTime(order.placedAt)
                            }}</span>
                            <span class="font-medium text-foreground">{{
                                formatCents(order.totalCents)
                            }}</span>
                        </div>
                    </Link>
                </li>
            </ul>
        </SectionCard>
    </div>
</template>
