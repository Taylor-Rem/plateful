export type CampaignStatusValue = App.Enums.CampaignStatus;

export const CAMPAIGN_STATUS_LABELS: Record<CampaignStatusValue, string> = {
    draft: 'Draft',
    pending_review: 'Pending review',
    scheduled: 'Scheduled',
    sending: 'Sending',
    sent: 'Sent',
    cancelled: 'Cancelled',
    paused_by_platform: 'Paused',
};

export function campaignStatusBadgeClasses(
    status: CampaignStatusValue,
): string {
    switch (status) {
        case 'draft':
            return 'bg-neutral-200 text-neutral-900 dark:bg-neutral-700 dark:text-neutral-100 border border-neutral-300 dark:border-neutral-600';
        case 'pending_review':
            return 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30';
        case 'scheduled':
            return 'bg-blue-100 text-blue-900 dark:bg-blue-500/15 dark:text-blue-300 border border-blue-200 dark:border-blue-500/30';
        case 'sending':
            return 'bg-indigo-100 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-500/30';
        case 'sent':
            return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30';
        case 'cancelled':
            return 'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300 border border-red-200 dark:border-red-500/30';
        case 'paused_by_platform':
            return 'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300 border border-red-200 dark:border-red-500/30';
        default:
            return 'bg-muted text-foreground border border-border';
    }
}
