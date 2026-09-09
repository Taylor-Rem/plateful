<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Send, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SectionCard from '@/components/admin/SectionCard.vue';
import StatCard from '@/components/admin/StatCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';
import {
    CAMPAIGN_STATUS_LABELS,
    campaignStatusBadgeClasses,
} from '@/lib/campaignStatus';
import {
    cancel as campaignsCancel,
    index as campaignsIndex,
    schedule as campaignsSchedule,
    send as campaignsSend,
} from '@/routes/admin/restaurant/campaigns';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    campaign: App.Data.CampaignData;
    previewHtml: string;
    sendBlocker: string | null;
}>();

const routeArgs = computed<[string, number]>(() => [
    props.restaurant.subdomain,
    props.campaign.id,
]);

const canSend = computed(() =>
    ['draft', 'scheduled', 'cancelled'].includes(props.campaign.status),
);

const scheduleForm = useForm({ scheduled_at: '' });

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

function sendNow(): void {
    const target =
        props.campaign.status === 'scheduled'
            ? 'now instead of the scheduled time'
            : 'now';

    if (!window.confirm(`Send "${props.campaign.subject}" ${target}?`)) {
        return;
    }

    router.post(
        campaignsSend.url(routeArgs.value),
        {},
        { preserveScroll: true },
    );
}

function schedule(): void {
    scheduleForm.post(campaignsSchedule.url(routeArgs.value), {
        preserveScroll: true,
    });
}

function cancelCampaign(): void {
    const what =
        props.campaign.status === 'pending_review'
            ? 'Withdraw this campaign from review?'
            : 'Cancel this scheduled campaign?';

    if (!window.confirm(what)) {
        return;
    }

    router.post(
        campaignsCancel.url(routeArgs.value),
        {},
        { preserveScroll: true },
    );
}

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head :title="campaign.subject" />

        <PageHeader :title="campaign.subject">
            <span
                class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                :class="campaignStatusBadgeClasses(campaign.status)"
            >
                {{ CAMPAIGN_STATUS_LABELS[campaign.status] }}
            </span>

            <template #actions>
                <Link
                    :href="campaignsIndex(props.restaurant.subdomain)"
                    class="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                >
                    <ArrowLeft class="size-4" />
                    Back to campaigns
                </Link>
            </template>
        </PageHeader>

        <div
            v-if="sendBlocker && canSend"
            class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
        >
            {{ sendBlocker }}
        </div>

        <div
            v-if="campaign.status === 'pending_review'"
            class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300"
        >
            <span>
                This campaign is getting a quick review before it goes out —
                usually about a minute. Nothing more to do.
            </span>
            <Button type="button" variant="outline" @click="cancelCampaign">
                Withdraw
            </Button>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Recipients" :value="campaign.recipientsCount" />
            <StatCard label="Delivered" :value="campaign.deliveredCount" />
            <StatCard
                label="Unsubscribed"
                :value="campaign.unsubscribedCount"
            />
            <StatCard label="Complaints" :value="campaign.complainedCount" />
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="grid content-start gap-6">
                <SectionCard title="Details">
                    <dl class="grid gap-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Audience</dt>
                            <dd class="text-right text-foreground">
                                {{ campaign.audienceLabel }}
                            </dd>
                        </div>
                        <div
                            v-if="campaign.scheduledAt"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Scheduled for</dt>
                            <dd class="text-right text-foreground">
                                {{ formatDateTime(campaign.scheduledAt) }}
                            </dd>
                        </div>
                        <div
                            v-if="campaign.sentAt"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Sent</dt>
                            <dd class="text-right text-foreground">
                                {{ formatDateTime(campaign.sentAt) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Created</dt>
                            <dd class="text-right text-foreground">
                                {{ formatDateTime(campaign.createdAt) }}
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard v-if="canSend" title="Send">
                    <div class="grid gap-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                :disabled="sendBlocker !== null"
                                @click="sendNow"
                            >
                                <Send class="size-4" />
                                Send now
                            </Button>
                            <Button
                                v-if="campaign.status === 'scheduled'"
                                type="button"
                                variant="outline"
                                @click="cancelCampaign"
                            >
                                <XCircle class="size-4" />
                                Cancel scheduled send
                            </Button>
                        </div>

                        <div class="grid gap-2">
                            <Label for="scheduled-at">
                                {{
                                    campaign.status === 'scheduled'
                                        ? 'Reschedule for'
                                        : 'Or schedule for later'
                                }}
                            </Label>
                            <div class="flex flex-wrap items-center gap-3">
                                <Input
                                    id="scheduled-at"
                                    v-model="scheduleForm.scheduled_at"
                                    type="datetime-local"
                                    class="max-w-60"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="
                                        !scheduleForm.scheduled_at ||
                                        scheduleForm.processing ||
                                        sendBlocker !== null
                                    "
                                    @click="schedule"
                                >
                                    Schedule
                                </Button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Times are in your restaurant's timezone ({{
                                    restaurant.timezone
                                }}).
                            </p>
                            <InputError
                                :message="scheduleForm.errors.scheduled_at"
                            />
                        </div>
                    </div>
                </SectionCard>
            </div>

            <SectionCard title="Email">
                <div
                    class="overflow-hidden rounded-md border border-border bg-white"
                >
                    <iframe
                        :srcdoc="previewHtml"
                        sandbox=""
                        title="Email preview"
                        class="h-[600px] w-full"
                    />
                </div>
            </SectionCard>
        </div>
    </div>
</template>
