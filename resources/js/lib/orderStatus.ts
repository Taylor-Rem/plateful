export type OrderStatusValue =
    | 'pending'
    | 'confirmed'
    | 'preparing'
    | 'ready'
    | 'completed'
    | 'cancelled';

export const ORDER_STATUSES: readonly OrderStatusValue[] = [
    'pending',
    'confirmed',
    'preparing',
    'ready',
    'completed',
    'cancelled',
] as const;

export const ORDER_STATUS_LABELS: Record<OrderStatusValue, string> = {
    pending: 'Pending',
    confirmed: 'Confirmed',
    preparing: 'Preparing',
    ready: 'Ready',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

/**
 * Legal forward transitions, mirroring App\Enums\OrderStatus::transitionMap()
 * on the PHP side.
 */
export const ORDER_STATUS_TRANSITIONS: Record<OrderStatusValue, OrderStatusValue[]> = {
    pending: ['confirmed', 'cancelled'],
    confirmed: ['preparing', 'cancelled'],
    preparing: ['ready', 'cancelled'],
    ready: ['completed', 'cancelled'],
    completed: [],
    cancelled: [],
};

export function statusBadgeClasses(status: OrderStatusValue | string): string {
    switch (status) {
        case 'pending':
            return 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30';
        case 'confirmed':
            return 'bg-blue-100 text-blue-900 dark:bg-blue-500/15 dark:text-blue-300 border border-blue-200 dark:border-blue-500/30';
        case 'preparing':
            return 'bg-indigo-100 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-500/30';
        case 'ready':
            return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30';
        case 'completed':
            return 'bg-neutral-200 text-neutral-900 dark:bg-neutral-700 dark:text-neutral-100 border border-neutral-300 dark:border-neutral-600';
        case 'cancelled':
            return 'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300 border border-red-200 dark:border-red-500/30';
        default:
            return 'bg-muted text-foreground border border-border';
    }
}

export function formatRelativeTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }
    const ts = new Date(iso).getTime();
    if (Number.isNaN(ts)) {
        return '';
    }
    const diffMs = Date.now() - ts;
    const diffSec = Math.round(diffMs / 1000);
    if (diffSec < 5) {
        return 'just now';
    }
    if (diffSec < 60) {
        return `${diffSec}s ago`;
    }
    const diffMin = Math.round(diffSec / 60);
    if (diffMin < 60) {
        return `${diffMin}m ago`;
    }
    const diffHr = Math.round(diffMin / 60);
    if (diffHr < 24) {
        return `${diffHr}h ago`;
    }
    const diffDay = Math.round(diffHr / 24);
    if (diffDay < 30) {
        return `${diffDay}d ago`;
    }
    return new Date(iso).toLocaleDateString();
}

export function formatCents(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

export function nextActions(status: OrderStatusValue | string): OrderStatusValue[] {
    return ORDER_STATUS_TRANSITIONS[status as OrderStatusValue] ?? [];
}
