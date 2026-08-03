<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Bike, CheckCircle2, ExternalLink, Phone } from 'lucide-vue-next';
import { computed, watch } from 'vue';

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    order: App.Data.OrderData;
}>();

const formatPrice = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

const isDelivery = computed(() => props.order.type === 'delivery');
const addr = computed(() => props.order.deliveryAddress);
const delivery = computed(() => props.order.delivery);

const formatEta = (iso: string | null): string | null => {
    if (!iso) {
        return null;
    }

    return new Date(iso).toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit',
    });
};

// A delivery order keeps refreshing until the courier lifecycle reaches a
// terminal state — before dispatch (`delivery` still null) it polls so the
// tracking card appears without a manual reload.
const deliveryInFlight = computed(
    () =>
        isDelivery.value &&
        props.order.status !== 'cancelled' &&
        (delivery.value?.isActive ?? true),
);

const { stop } = usePoll(
    15000,
    { only: ['order'] },
    { autoStart: deliveryInFlight.value },
);

watch(deliveryInFlight, (active) => {
    if (!active) {
        stop();
    }
});
</script>

<template>
    <Head :title="`Order ${order.number}`" />

    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <div class="rounded-lg border border-border bg-card p-6 sm:p-8">
            <div class="flex items-center gap-3">
                <CheckCircle2
                    class="size-8"
                    :style="{ color: 'var(--brand-primary)' }"
                />
                <h1 class="text-2xl font-bold tracking-tight">
                    Thanks for your order!
                </h1>
            </div>

            <p class="mt-2 text-muted-foreground">
                We've received your order at
                <strong>{{ restaurant.name }}</strong
                >.
            </p>

            <div class="mt-6 rounded-md border border-border bg-background p-5">
                <p
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    Order number
                </p>
                <p
                    class="mt-1 font-mono text-2xl font-bold tracking-widest"
                    :style="{ color: 'var(--brand-primary)' }"
                >
                    {{ order.number }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium tracking-wide uppercase"
                        :style="{
                            backgroundColor: 'var(--brand-primary)',
                            color: 'var(--brand-primary-foreground)',
                        }"
                    >
                        {{ order.status }}
                    </span>
                    <span class="text-muted-foreground"
                        >· {{ isDelivery ? 'Delivery' : 'Pickup' }}</span
                    >
                </div>
            </div>

            <section
                v-if="isDelivery && order.status !== 'cancelled'"
                class="mt-6 rounded-md border border-border bg-background p-5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <Bike class="size-4" />
                    Delivery status
                </h2>

                <template v-if="delivery">
                    <p class="mt-2 text-lg font-semibold">
                        {{ delivery.statusLabel }}
                    </p>
                    <p
                        v-if="formatEta(delivery.dropoffEtaAt)"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Estimated dropoff
                        {{ formatEta(delivery.dropoffEtaAt) }}
                    </p>
                    <p
                        v-if="delivery.driverName"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Your courier is {{ delivery.driverName }}.
                    </p>

                    <a
                        v-if="delivery.trackingUrl"
                        :href="delivery.trackingUrl"
                        target="_blank"
                        rel="noopener"
                        class="mt-4 inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium"
                        :style="{
                            backgroundColor: 'var(--brand-primary)',
                            color: 'var(--brand-primary-foreground)',
                        }"
                    >
                        Track your delivery
                        <ExternalLink class="size-3.5" />
                    </a>
                </template>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    We're lining up your courier. This page will update as soon
                    as your delivery is on its way.
                </p>

                <p
                    v-if="restaurant.phoneDisplay"
                    class="mt-4 flex items-center gap-2 border-t border-border pt-3 text-sm text-muted-foreground"
                >
                    <Phone class="size-3.5" />
                    <span>
                        Questions about your order? Call
                        {{ restaurant.name }} at
                        <a
                            :href="restaurant.phoneHref ?? undefined"
                            class="font-medium underline underline-offset-2"
                            >{{ restaurant.phoneDisplay }}</a
                        >.
                    </span>
                </p>
            </section>

            <section class="mt-6">
                <h2
                    class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Customer
                </h2>
                <p class="mt-1 text-sm">{{ order.customerName }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ order.customerEmail }}
                </p>
                <p
                    v-if="order.customerPhone"
                    class="text-sm text-muted-foreground"
                >
                    {{ order.customerPhone }}
                </p>
            </section>

            <section v-if="isDelivery && addr" class="mt-6">
                <h2
                    class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Delivery to
                </h2>
                <p class="mt-1 text-sm">
                    {{ addr.street }}<br v-if="addr.street2" />
                    <template v-if="addr.street2"
                        >{{ addr.street2 }}<br
                    /></template>
                    {{ addr.city }}, {{ addr.state }} {{ addr.postal_code }}
                </p>
                <p
                    v-if="addr.instructions"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ addr.instructions }}
                </p>
            </section>

            <section v-else class="mt-6">
                <h2
                    class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Pickup
                </h2>
                <p class="mt-1 text-sm">
                    We'll email you when your order is ready for pickup.
                </p>
            </section>

            <section class="mt-6">
                <h2
                    class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Items
                </h2>
                <ul class="mt-2 divide-y divide-border">
                    <li
                        v-for="item in order.items"
                        :key="item.id"
                        class="flex justify-between gap-4 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">
                                {{ item.quantity }}× {{ item.name }}
                            </p>
                            <p
                                v-if="item.modifierSummary"
                                class="text-xs text-muted-foreground"
                            >
                                {{ item.modifierSummary }}
                            </p>
                            <p
                                v-if="item.notes"
                                class="text-xs text-muted-foreground italic"
                            >
                                “{{ item.notes }}”
                            </p>
                        </div>
                        <span class="text-sm tabular-nums">{{
                            formatPrice(item.subtotalCents)
                        }}</span>
                    </li>
                </ul>
            </section>

            <section class="mt-6 border-t border-border pt-4">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd class="tabular-nums">
                            {{ formatPrice(order.subtotalCents) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Tax</dt>
                        <dd class="tabular-nums">
                            {{ formatPrice(order.taxCents) }}
                        </dd>
                    </div>
                    <div
                        v-if="order.deliveryFeeCents > 0"
                        class="flex justify-between"
                    >
                        <dt class="text-muted-foreground">Delivery fee</dt>
                        <dd class="tabular-nums">
                            {{ formatPrice(order.deliveryFeeCents) }}
                        </dd>
                    </div>
                    <div v-if="order.tipCents > 0" class="flex justify-between">
                        <dt class="text-muted-foreground">Tip</dt>
                        <dd class="tabular-nums">
                            {{ formatPrice(order.tipCents) }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between border-t border-border pt-3 text-base font-bold"
                        :style="{ color: 'var(--brand-primary)' }"
                    >
                        <dt>Total</dt>
                        <dd class="tabular-nums">
                            {{ formatPrice(order.totalCents) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <p
                v-if="order.notes"
                class="mt-6 rounded-md border border-border bg-muted/40 p-3 text-sm text-muted-foreground"
            >
                <em>{{ order.notes }}</em>
            </p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <Link
                    href="/"
                    class="flex-1 rounded-md border border-border px-4 py-2 text-center text-sm font-medium hover:bg-muted"
                >
                    Continue shopping
                </Link>
            </div>
        </div>
    </main>
</template>
