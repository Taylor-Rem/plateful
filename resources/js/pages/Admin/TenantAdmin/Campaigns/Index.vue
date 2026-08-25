<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Megaphone, Plus } from 'lucide-vue-next';
import EmptyState from '@/components/admin/EmptyState.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import {
    CAMPAIGN_STATUS_LABELS,
    campaignStatusBadgeClasses,
} from '@/lib/campaignStatus';
import {
    create as campaignsCreate,
    show as campaignsShow,
} from '@/routes/admin/restaurant/campaigns';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    campaigns: App.Data.CampaignData[];
    optedInCount: number;
}>();

function campaignDate(campaign: App.Data.CampaignData): string {
    const iso = campaign.sentAt ?? campaign.scheduledAt ?? campaign.createdAt;

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
        <Head title="Campaigns" />

        <PageHeader
            title="Campaigns"
            description="Email offers and news to customers who opted in — free, from your own customer list."
        >
            <template #actions>
                <Link
                    :href="campaignsCreate(props.restaurant.subdomain)"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                >
                    <Plus class="size-4" />
                    New campaign
                </Link>
            </template>
        </PageHeader>

        <div v-if="campaigns.length === 0" class="mt-8">
            <EmptyState
                :icon="Megaphone"
                :title="
                    optedInCount > 0
                        ? `You have ${optedInCount} ${optedInCount === 1 ? 'customer' : 'customers'} opted into marketing`
                        : 'No opted-in customers yet'
                "
                :description="
                    optedInCount > 0
                        ? 'Send them an offer — a slow-night special, a new menu item, a holiday reminder. It lands in their inbox from your name.'
                        : 'Customers can opt into your emails at checkout or from their account page. Once someone opts in, you can send campaigns here.'
                "
            >
                <template v-if="optedInCount > 0" #actions>
                    <Link
                        :href="campaignsCreate(props.restaurant.subdomain)"
                        class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                    >
                        <Plus class="size-4" />
                        Create your first campaign
                    </Link>
                </template>
            </EmptyState>
        </div>

        <div
            v-else
            class="mt-6 overflow-hidden rounded-lg border border-border bg-card"
        >
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Campaign</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="hidden px-4 py-3 font-medium md:table-cell">
                            Audience
                        </th>
                        <th class="px-4 py-3 text-right font-medium">Sent</th>
                        <th
                            class="hidden px-4 py-3 text-right font-medium sm:table-cell"
                        >
                            Delivered
                        </th>
                        <th
                            class="hidden px-4 py-3 text-right font-medium sm:table-cell"
                        >
                            Unsubscribed
                        </th>
                        <th
                            class="hidden px-4 py-3 text-right font-medium sm:table-cell"
                        >
                            Complaints
                        </th>
                        <th class="px-4 py-3 font-medium">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr
                        v-for="campaign in campaigns"
                        :key="campaign.id"
                        class="transition hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="
                                    campaignsShow([
                                        props.restaurant.subdomain,
                                        campaign.id,
                                    ])
                                "
                                class="block font-medium text-foreground hover:underline"
                            >
                                {{ campaign.subject }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="
                                    campaignStatusBadgeClasses(campaign.status)
                                "
                            >
                                {{ CAMPAIGN_STATUS_LABELS[campaign.status] }}
                            </span>
                        </td>
                        <td
                            class="hidden px-4 py-3 text-xs text-muted-foreground md:table-cell"
                        >
                            {{ campaign.audienceLabel }}
                        </td>
                        <td
                            class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                        >
                            {{ campaign.recipientsCount }}
                        </td>
                        <td
                            class="hidden px-4 py-3 text-right text-muted-foreground tabular-nums sm:table-cell"
                        >
                            {{ campaign.deliveredCount }}
                        </td>
                        <td
                            class="hidden px-4 py-3 text-right text-muted-foreground tabular-nums sm:table-cell"
                        >
                            {{ campaign.unsubscribedCount }}
                        </td>
                        <td
                            class="hidden px-4 py-3 text-right text-muted-foreground tabular-nums sm:table-cell"
                        >
                            {{ campaign.complainedCount }}
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ campaignDate(campaign) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
