<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeft, Bike, ShoppingBag } from 'lucide-vue-next';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    formatCents,
    formatRelativeTime,
    nextActions,
    ORDER_STATUS_LABELS,
    statusBadgeClasses,
    type OrderStatusValue,
} from '@/lib/orderStatus';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    order: App.Data.OrderData;
    events: App.Data.OrderEventData[];
}>();

const allowed = computed<OrderStatusValue[]>(() => nextActions(props.order.status));
const cancelOpen = ref(false);
const cancelReason = ref('');
const submitting = ref(false);

function transition(toStatus: OrderStatusValue, note?: string): void {
    submitting.value = true;
    router.post(
        `/${props.restaurant.subdomain}/orders/${props.order.number}/transitions`,
        { to_status: toStatus, note: note ?? null },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
            },
            onSuccess: () => {
                cancelOpen.value = false;
                cancelReason.value = '';
            },
        },
    );
}

function labelForAction(status: OrderStatusValue): string {
    if (status === 'ready' && props.order.type === 'pickup') {
        return 'Mark ready for pickup';
    }
    if (status === 'ready') {
        return 'Mark ready';
    }
    if (status === 'cancelled') {
        return 'Cancel order';
    }
    if (status === 'completed') {
        return 'Mark completed';
    }
    return `Mark ${ORDER_STATUS_LABELS[status].toLowerCase()}`;
}

function handleActionClick(status: OrderStatusValue): void {
    if (status === 'cancelled') {
        cancelOpen.value = true;
        return;
    }
    transition(status);
}

function confirmCancel(): void {
    transition('cancelled', cancelReason.value.trim() || undefined);
}
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`Order ${order.number}`" />

        <div class="flex items-center gap-2 text-sm">
            <Link
                :href="`/${restaurant.subdomain}/orders`"
                class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-3.5" />
                Back to orders
            </Link>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <h2 class="text-2xl font-semibold text-foreground">
                Order <span class="font-mono">#{{ order.number }}</span>
            </h2>
            <span
                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                :class="statusBadgeClasses(order.status)"
            >
                {{ order.status }}
            </span>
            <span class="text-xs text-muted-foreground">Placed {{ formatRelativeTime(order.placedAt) }}</span>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-lg border border-border bg-card p-5">
                    <h3 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">Customer</h3>
                    <div class="mt-3 grid gap-1 text-sm">
                        <div class="text-foreground">{{ order.customerName }}</div>
                        <div class="text-muted-foreground">{{ order.customerEmail || '—' }}</div>
                        <div class="text-muted-foreground">{{ order.customerPhone || '—' }}</div>
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-card p-5">
                    <h3 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">
                        <span class="inline-flex items-center gap-2">
                            <ShoppingBag v-if="order.type === 'pickup'" class="size-4" />
                            <Bike v-else class="size-4" />
                            {{ order.type === 'pickup' ? 'Pickup' : 'Delivery' }}
                        </span>
                    </h3>
                    <div v-if="order.type === 'delivery' && order.deliveryAddress" class="mt-3 text-sm text-foreground">
                        <div>{{ order.deliveryAddress.street }}</div>
                        <div v-if="order.deliveryAddress.street2">{{ order.deliveryAddress.street2 }}</div>
                        <div>
                            {{ order.deliveryAddress.city }}, {{ order.deliveryAddress.state }}
                            {{ order.deliveryAddress.postal_code }}
                        </div>
                        <div v-if="order.deliveryAddress.instructions" class="mt-2 text-xs text-muted-foreground">
                            Notes: {{ order.deliveryAddress.instructions }}
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-card p-5">
                    <h3 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">Items</h3>
                    <ul class="mt-3 divide-y divide-border">
                        <li v-for="line in order.items" :key="line.id" class="flex items-start justify-between py-3 text-sm">
                            <div>
                                <div class="font-medium text-foreground">{{ line.quantity }}× {{ line.name }}</div>
                                <div v-if="line.modifierGroups.length > 0" class="mt-0.5 text-xs text-muted-foreground">
                                    <div v-for="g in line.modifierGroups" :key="g.groupName">
                                        <span class="font-medium">{{ g.groupName }}:</span> {{ g.selectionNames.join(', ') }}
                                    </div>
                                </div>
                            </div>
                            <div class="tabular-nums text-foreground">{{ formatCents(line.subtotalCents) }}</div>
                        </li>
                    </ul>

                    <dl class="mt-4 space-y-1 border-t border-border pt-4 text-sm">
                        <div class="flex justify-between text-muted-foreground">
                            <dt>Subtotal</dt>
                            <dd class="tabular-nums">{{ formatCents(order.subtotalCents) }}</dd>
                        </div>
                        <div class="flex justify-between text-muted-foreground">
                            <dt>Tax</dt>
                            <dd class="tabular-nums">{{ formatCents(order.taxCents) }}</dd>
                        </div>
                        <div v-if="order.deliveryFeeCents > 0" class="flex justify-between text-muted-foreground">
                            <dt>Delivery fee</dt>
                            <dd class="tabular-nums">{{ formatCents(order.deliveryFeeCents) }}</dd>
                        </div>
                        <div v-if="order.tipCents > 0" class="flex justify-between text-muted-foreground">
                            <dt>Tip</dt>
                            <dd class="tabular-nums">{{ formatCents(order.tipCents) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-border pt-2 text-base font-semibold text-foreground">
                            <dt>Total</dt>
                            <dd class="tabular-nums">{{ formatCents(order.totalCents) }}</dd>
                        </div>
                    </dl>

                    <p v-if="order.notes" class="mt-4 rounded-md bg-muted/40 p-3 text-sm italic text-muted-foreground">
                        Notes: {{ order.notes }}
                    </p>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg border border-border bg-card p-5">
                    <h3 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">Status</h3>
                    <div class="mt-3">
                        <span
                            class="inline-flex rounded-full px-3 py-1 text-sm font-medium capitalize"
                            :class="statusBadgeClasses(order.status)"
                        >
                            {{ order.status }}
                        </span>
                    </div>

                    <div v-if="allowed.length > 0" class="mt-4 space-y-2">
                        <Button
                            v-for="action in allowed"
                            :key="action"
                            type="button"
                            :variant="action === 'cancelled' ? 'destructive' : 'default'"
                            class="w-full"
                            :disabled="submitting"
                            @click="handleActionClick(action)"
                        >
                            {{ labelForAction(action) }}
                        </Button>
                    </div>
                    <p v-else class="mt-4 text-xs text-muted-foreground">
                        This order is in a final state. No further transitions are available.
                    </p>
                </section>

                <section class="rounded-lg border border-border bg-card p-5">
                    <h3 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">Timeline</h3>
                    <ol class="mt-4 space-y-4">
                        <li v-for="event in events" :key="event.id" class="relative pl-5">
                            <span
                                class="absolute left-0 top-1.5 inline-block size-2 rounded-full"
                                :class="statusBadgeClasses(event.toStatus).split(' ').filter((c) => c.startsWith('bg-')).join(' ')"
                            />
                            <div class="text-sm font-medium text-foreground capitalize">
                                {{ event.fromStatus ? `${event.fromStatus} → ${event.toStatus}` : event.toStatus }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ formatRelativeTime(event.occurredAt) }}
                                <span v-if="event.userName"> · by {{ event.userName }}</span>
                            </div>
                            <p v-if="event.note" class="mt-1 text-xs italic text-muted-foreground">{{ event.note }}</p>
                        </li>
                    </ol>
                </section>
            </aside>
        </div>

        <Dialog v-model:open="cancelOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel this order?</DialogTitle>
                    <DialogDescription>
                        We'll email the customer to let them know. You can optionally include a reason.
                    </DialogDescription>
                </DialogHeader>
                <textarea
                    v-model="cancelReason"
                    rows="3"
                    placeholder="Reason (optional)"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                />
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="submitting" @click="cancelOpen = false">
                        Keep order
                    </Button>
                    <Button type="button" variant="destructive" :disabled="submitting" @click="confirmCancel">
                        Cancel order
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </TenantAdminLayout>
</template>
