<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, Megaphone, PauseCircle } from 'lucide-vue-next';
import EmptyState from '@/components/admin/EmptyState.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SectionCard from '@/components/admin/SectionCard.vue';
import { Button } from '@/components/ui/button';
import SuperAdminLayout from '@/layouts/admin/SuperAdminLayout.vue';
import {
    approve as campaignsApprove,
    reject as campaignsReject,
    unpause as campaignsUnpause,
} from '@/routes/admin/super/campaigns';

type ReviewRow = {
    campaign: App.Data.CampaignData;
    restaurantName: string;
    restaurantSubdomain: string;
    restaurantPaused: boolean;
    reviewVerdict: string | null;
    reviewNotes: string | null;
    previewHtml?: string;
};

defineProps<{
    pending: ReviewRow[];
    paused: ReviewRow[];
}>();

function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function approve(row: ReviewRow): void {
    if (
        !window.confirm(
            `Approve and send "${row.campaign.subject}" for ${row.restaurantName}?`,
        )
    ) {
        return;
    }

    router.post(
        campaignsApprove.url(row.campaign.id),
        {},
        { preserveScroll: true },
    );
}

function reject(row: ReviewRow): void {
    if (
        !window.confirm(
            `Reject "${row.campaign.subject}"? It returns to the owner's drafts.`,
        )
    ) {
        return;
    }

    router.post(
        campaignsReject.url(row.campaign.id),
        {},
        { preserveScroll: true },
    );
}

function unpause(row: ReviewRow): void {
    if (
        !window.confirm(
            `Let ${row.restaurantName} send campaigns again? The paused campaign itself stays halted.`,
        )
    ) {
        return;
    }

    router.post(
        campaignsUnpause.url(row.restaurantSubdomain),
        {},
        { preserveScroll: true },
    );
}

defineOptions({ layout: SuperAdminLayout });
</script>

<template>
    <div>
        <Head title="Campaign review" />

        <PageHeader
            title="Campaign review"
            description="First campaigns held for approval, and restaurants paused by the complaint auto-pause."
        />

        <h2 class="mt-8 text-base font-semibold text-foreground">
            Awaiting review
        </h2>

        <div v-if="pending.length === 0" class="mt-3">
            <EmptyState
                :icon="Megaphone"
                title="No campaigns waiting"
                description="A restaurant's first campaign lands here before it can send."
            />
        </div>

        <div v-else class="mt-3 grid gap-6">
            <SectionCard
                v-for="row in pending"
                :key="row.campaign.id"
                :title="row.campaign.subject"
                :description="`${row.restaurantName} (${row.restaurantSubdomain}) · ${row.campaign.audienceLabel}`"
            >
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="grid content-start gap-4">
                        <dl class="grid gap-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Requested</dt>
                                <dd class="text-foreground">
                                    {{
                                        row.campaign.scheduledAt
                                            ? `Scheduled for ${formatDateTime(row.campaign.scheduledAt)}`
                                            : 'Send immediately on approval'
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Submitted</dt>
                                <dd class="text-foreground">
                                    {{ formatDateTime(row.campaign.createdAt) }}
                                </dd>
                            </div>
                        </dl>
                        <div
                            v-if="row.reviewNotes"
                            class="rounded-md border px-3 py-2 text-sm"
                            :class="
                                row.reviewVerdict === 'flagged'
                                    ? 'border-red-200 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300'
                                    : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'
                            "
                        >
                            <span class="font-medium">
                                {{
                                    row.reviewVerdict === 'flagged'
                                        ? 'Claude flagged this campaign:'
                                        : 'Automated review:'
                                }}
                            </span>
                            {{ row.reviewNotes }}
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <Button type="button" @click="approve(row)">
                                <CheckCircle2 class="size-4" />
                                Approve &amp; send
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                @click="reject(row)"
                            >
                                Reject
                            </Button>
                        </div>
                    </div>
                    <div
                        class="overflow-hidden rounded-md border border-border bg-white"
                    >
                        <iframe
                            v-if="row.previewHtml"
                            :srcdoc="row.previewHtml"
                            sandbox=""
                            :title="`Preview of ${row.campaign.subject}`"
                            class="h-96 w-full"
                        />
                    </div>
                </div>
            </SectionCard>
        </div>

        <h2 class="mt-10 text-base font-semibold text-foreground">
            Paused by complaints
        </h2>

        <div v-if="paused.length === 0" class="mt-3">
            <EmptyState
                :icon="PauseCircle"
                title="No paused campaigns"
                description="Campaigns halted by the complaint auto-pause show up here for review."
            />
        </div>

        <div
            v-else
            class="mt-3 overflow-hidden rounded-lg border border-border bg-card"
        >
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Restaurant</th>
                        <th class="px-4 py-3 font-medium">Campaign</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Recipients
                        </th>
                        <th class="px-4 py-3 text-right font-medium">
                            Complaints
                        </th>
                        <th class="px-4 py-3 font-medium">Sending</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in paused" :key="row.campaign.id">
                        <td class="px-4 py-3 font-medium text-foreground">
                            {{ row.restaurantName }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ row.campaign.subject }}
                        </td>
                        <td
                            class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                        >
                            {{ row.campaign.recipientsCount }}
                        </td>
                        <td
                            class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                        >
                            {{ row.campaign.complainedCount }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="row.restaurantPaused"
                                class="inline-flex rounded-full border border-red-200 bg-red-100 px-2 py-0.5 text-xs font-medium text-red-900 dark:border-red-500/30 dark:bg-red-500/15 dark:text-red-300"
                            >
                                Paused
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >Resumed</span
                            >
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="row.restaurantPaused"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="unpause(row)"
                            >
                                Resume sending
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
