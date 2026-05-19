<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CheckCircle2 } from 'lucide-vue-next';

type BrandPalette = {
    primary: string;
    primaryForeground: string;
    secondary: string;
    secondaryForeground: string;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    order: App.Data.OrderData;
    brand: BrandPalette;
}>();

const formatPrice = (cents: number): string =>
    `$${(cents / 100).toFixed(2)}`;

const isDelivery = computed(() => props.order.type === 'delivery');
const addr = computed(() => props.order.deliveryAddress);
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
                We've received your order at <strong>{{ restaurant.name }}</strong>.
            </p>

            <div
                class="mt-6 rounded-md border border-border bg-background p-5"
            >
                <p class="text-xs uppercase tracking-wide text-muted-foreground">
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
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium uppercase tracking-wide"
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

            <section class="mt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    Customer
                </h2>
                <p class="mt-1 text-sm">{{ order.customerName }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ order.customerEmail }}
                </p>
                <p v-if="order.customerPhone" class="text-sm text-muted-foreground">
                    {{ order.customerPhone }}
                </p>
            </section>

            <section v-if="isDelivery && addr" class="mt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    Delivery to
                </h2>
                <p class="mt-1 text-sm">
                    {{ addr.street }}<br v-if="addr.street2" />
                    <template v-if="addr.street2">{{ addr.street2 }}<br /></template>
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
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    Pickup
                </h2>
                <p class="mt-1 text-sm">
                    We'll email you when your order is ready for pickup.
                </p>
            </section>

            <section class="mt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
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
