<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/admin/PageHeader.vue';
import StatCard from '@/components/admin/StatCard.vue';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import { formatCents } from '@/lib/orderStatus';
import { index as payoutsIndex } from '@/routes/admin/restaurant/payouts';

type Payout = {
    id: string;
    amountCents: number;
    currency: string;
    status: string;
    arrivalDate: string | null;
    createdAt: string | null;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    payouts: Payout[];
    hasMore: boolean;
    ytdFeesCents: number;
    currentYear: number;
    stripeConnected: boolean;
    dashboardPath: string;
}>();

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return '—';
    }
}

const lastPayoutId = props.payouts.length
    ? props.payouts[props.payouts.length - 1].id
    : null;

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="Payouts" />

        <div class="space-y-6">
            <PageHeader
                title="Payouts"
                description="Money Stripe has sent to your bank, and the Plateful fees you've paid this year."
            >
                <template #actions>
                    <a
                        v-if="stripeConnected"
                        :href="dashboardPath"
                        class="rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
                    >
                        Update bank info
                    </a>
                </template>
            </PageHeader>

            <StatCard
                :label="`Plateful fees paid in ${currentYear}`"
                hint="4% per order on the food subtotal. That's it."
            >
                <span data-test="ytd-fees">{{
                    formatCents(ytdFeesCents)
                }}</span>
            </StatCard>

            <section
                v-if="!stripeConnected"
                class="rounded-lg border border-amber-300 bg-amber-50 p-6 text-amber-900"
                data-test="not-connected"
            >
                <h2 class="text-base font-semibold">
                    Stripe isn't connected yet
                </h2>
                <p class="mt-1 text-sm">
                    Finish connecting Stripe from your onboarding checklist to
                    start taking payments and receiving payouts.
                </p>
            </section>

            <section v-else class="rounded-lg border border-border bg-card">
                <header class="border-b border-border px-6 py-3">
                    <h2 class="text-base font-semibold">Recent payouts</h2>
                </header>

                <div
                    v-if="payouts.length === 0"
                    class="px-6 py-10 text-center text-sm text-muted-foreground"
                >
                    No payouts yet. They'll show up here once Stripe sends your
                    first one.
                </div>

                <table v-else class="w-full text-sm">
                    <thead
                        class="text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr class="border-b border-border">
                            <th class="px-6 py-3 font-medium">Amount</th>
                            <th class="px-6 py-3 font-medium">Status</th>
                            <th class="px-6 py-3 font-medium">Expected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="payout in payouts"
                            :key="payout.id"
                            class="border-b border-border last:border-0"
                        >
                            <td class="px-6 py-3 font-medium">
                                {{ formatCents(payout.amountCents) }}
                            </td>
                            <td
                                class="px-6 py-3 text-muted-foreground capitalize"
                            >
                                {{ payout.status }}
                            </td>
                            <td class="px-6 py-3 text-muted-foreground">
                                {{ formatDate(payout.arrivalDate) }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <footer
                    v-if="hasMore && lastPayoutId"
                    class="border-t border-border px-6 py-3 text-center"
                >
                    <Link
                        :href="
                            payoutsIndex.url(restaurant.subdomain, {
                                query: {
                                    starting_after: lastPayoutId ?? undefined,
                                },
                            })
                        "
                        class="text-sm font-medium text-primary hover:opacity-80"
                    >
                        Load older payouts
                    </Link>
                </footer>
            </section>
        </div>
    </div>
</template>
