<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Truck } from 'lucide-vue-next';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import { Button } from '@/components/ui/button';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';

type DeliveryProviderCard = {
    provider: string;
    label: string;
    status: string;
    lastError: string | null;
    connectedAt: string | null;
    storeId: string | null;
    available: boolean;
    saveUrl: string | null;
    disconnectUrl: string | null;
};

type Option = { value: string; label: string };

type DeliverySettings = {
    deliveryEnabled: boolean;
    deliveryMode: string | null;
    deliveryFee: string;
    deliveryFeeStrategy: string;
    prepTimeMinutes: number;
    selfDeliveryTipRecipient: string;
    deliveryFallbackAction: string;
    restrictedItemsAttestedAt: string | null;
    saveUrl: string;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    providers: DeliveryProviderCard[];
    settings: DeliverySettings;
    options: {
        modes: Option[];
        feeStrategies: Option[];
        tipRecipients: Option[];
        fallbackActions: Option[];
    };
}>();

const settingsForm = useForm({
    delivery_enabled: props.settings.deliveryEnabled,
    delivery_mode: props.settings.deliveryMode,
    delivery_fee: props.settings.deliveryFee,
    delivery_fee_strategy: props.settings.deliveryFeeStrategy,
    prep_time_minutes: props.settings.prepTimeMinutes,
    self_delivery_tip_recipient: props.settings.selfDeliveryTipRecipient,
    delivery_fallback_action: props.settings.deliveryFallbackAction,
    restricted_items_attested:
        props.settings.restrictedItemsAttestedAt !== null,
});

const alreadyAttested = props.settings.restrictedItemsAttestedAt !== null;

const isSelfDelivery = computed(() => settingsForm.delivery_mode === 'self');

const saveSettings = (): void => {
    settingsForm.put(props.settings.saveUrl, { preserveScroll: true });
};

const disconnectForm = useForm({});

// Both providers enable in one click with no credential form — the button
// posts straight to saveUrl and Plateful provisions the restaurant's
// provider-side identity (DoorDash Business/Store, Uber sub-organization).
const enableForm = useForm({});

const enable = (card: DeliveryProviderCard): void => {
    if (!card.saveUrl) {
        return;
    }

    enableForm.post(card.saveUrl, { preserveScroll: true });
};

const disconnect = (card: DeliveryProviderCard): void => {
    if (
        card.disconnectUrl &&
        confirm(
            `Disconnect ${card.label}? New delivery orders will stop being dispatched to couriers.`,
        )
    ) {
        disconnectForm.post(card.disconnectUrl, { preserveScroll: true });
    }
};

const statusLabels: Record<string, string> = {
    connected: 'Connected',
    disconnected: 'Not connected',
    error: 'Error',
};

const statusClasses: Record<string, string> = {
    connected: 'bg-green-100 text-green-800',
    disconnected: 'bg-muted text-muted-foreground',
    error: 'bg-red-100 text-red-800',
};

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="Delivery" />

        <div class="mx-auto max-w-3xl space-y-6">
            <PageHeader
                title="Delivery"
                description="Connect a courier network so delivery orders are dispatched automatically. Both networks enable in one click — no accounts to create or credentials to paste."
            />

            <!-- The behaviour flags. Every one of these lived in the schema
                 with no UI, so a restaurant could have delivery on with no mode
                 set and nobody could tell. -->
            <section class="rounded-lg border border-border bg-card p-5">
                <h2 class="mb-4 text-base font-semibold">Delivery settings</h2>

                <form class="space-y-4" @submit.prevent="saveSettings">
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="settingsForm.delivery_enabled"
                            type="checkbox"
                            class="rounded"
                        />
                        Offer delivery at checkout
                    </label>

                    <template v-if="settingsForm.delivery_enabled">
                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >Who delivers?</label
                            >
                            <select
                                v-model="settingsForm.delivery_mode"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option :value="null">Choose…</option>
                                <option
                                    v-for="m in options.modes"
                                    :key="m.value"
                                    :value="m.value"
                                >
                                    {{ m.label }}
                                </option>
                            </select>
                            <p
                                v-if="settingsForm.errors.delivery_mode"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ settingsForm.errors.delivery_mode }}
                            </p>
                        </div>

                        <!-- The fee strategy no longer applies to courier
                             networks: both are centrally billed, so the
                             customer always pays the (marked-up) courier
                             quote. Showing the selector would promise pricing
                             control the restaurant doesn't have. -->
                        <p
                            v-if="!isSelfDelivery"
                            class="text-xs text-muted-foreground"
                        >
                            Customers pay the courier network's quoted delivery
                            fee at checkout, priced live for their address.
                        </p>

                        <div v-if="isSelfDelivery">
                            <label class="mb-1 block text-sm font-medium"
                                >Your delivery fee ($)</label
                            >
                            <input
                                v-model="settingsForm.delivery_fee"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p
                                v-if="settingsForm.errors.delivery_fee"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ settingsForm.errors.delivery_fee }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >Kitchen prep time (minutes)</label
                            >
                            <input
                                v-model.number="settingsForm.prep_time_minutes"
                                type="number"
                                min="0"
                                max="180"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                How long a ticket takes. Couriers are timed to
                                arrive when the food is ready, and customers are
                                quoted an arrival time that includes it.
                            </p>
                            <p
                                v-if="settingsForm.errors.prep_time_minutes"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ settingsForm.errors.prep_time_minutes }}
                            </p>
                        </div>

                        <div v-if="isSelfDelivery">
                            <label class="mb-1 block text-sm font-medium"
                                >Who gets the tip?</label
                            >
                            <select
                                v-model="
                                    settingsForm.self_delivery_tip_recipient
                                "
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="t in options.tipRecipients"
                                    :key="t.value"
                                    :value="t.value"
                                >
                                    {{ t.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-muted-foreground">
                                On courier-network deliveries the tip always
                                goes to the courier, so this only applies to
                                your own drivers.
                            </p>
                        </div>

                        <div v-else>
                            <label class="mb-1 block text-sm font-medium"
                                >If no courier can be found</label
                            >
                            <select
                                v-model="settingsForm.delivery_fallback_action"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="a in options.fallbackActions"
                                    :key="a.value"
                                    :value="a.value"
                                >
                                    {{ a.label }}
                                </option>
                            </select>
                        </div>

                        <div
                            class="rounded-md border border-border bg-muted/40 p-4"
                        >
                            <h3 class="text-sm font-medium">
                                Restricted items policy
                            </h3>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Plateful delivery is food-only. Alcohol,
                                tobacco, cannabis, weapons, and explosives may
                                not be sold through Plateful delivery — courier
                                networks require this, and orders containing
                                restricted items are automatically blocked from
                                dispatch.
                            </p>
                            <label class="mt-3 flex items-start gap-2 text-sm">
                                <input
                                    v-model="
                                        settingsForm.restricted_items_attested
                                    "
                                    type="checkbox"
                                    class="mt-0.5 rounded"
                                    :disabled="alreadyAttested"
                                />
                                <span>
                                    I confirm this restaurant will not sell
                                    restricted items through Plateful delivery.
                                    <span
                                        v-if="alreadyAttested"
                                        class="text-muted-foreground"
                                        >(accepted
                                        {{
                                            new Date(
                                                settings.restrictedItemsAttestedAt!,
                                            ).toLocaleDateString()
                                        }})</span
                                    >
                                </span>
                            </label>
                            <p
                                v-if="
                                    settingsForm.errors
                                        .restricted_items_attested
                                "
                                class="mt-1 text-xs text-destructive"
                            >
                                {{
                                    settingsForm.errors
                                        .restricted_items_attested
                                }}
                            </p>
                        </div>
                    </template>

                    <Button
                        type="submit"
                        size="sm"
                        :disabled="settingsForm.processing"
                    >
                        {{
                            settingsForm.processing
                                ? 'Saving…'
                                : 'Save settings'
                        }}
                    </Button>
                </form>
            </section>

            <section
                v-for="card in providers"
                :key="card.provider"
                class="rounded-lg border border-border bg-card p-4"
                :data-test="`delivery-provider-${card.provider}`"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-0.5 flex h-6 w-6 items-center justify-center rounded-full border border-border text-muted-foreground"
                        >
                            <Truck class="size-3.5" />
                        </span>
                        <div>
                            <h3 class="text-sm font-medium">
                                {{ card.label }}
                                <span
                                    class="ml-2 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        statusClasses[card.status] ??
                                        statusClasses.disconnected
                                    "
                                >
                                    {{
                                        statusLabels[card.status] ?? card.status
                                    }}
                                </span>
                            </h3>
                            <p
                                v-if="
                                    card.storeId && card.status === 'connected'
                                "
                                class="mt-1 font-mono text-xs break-all text-muted-foreground"
                            >
                                Account ID {{ card.storeId }}
                            </p>
                            <p
                                v-if="card.lastError"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ card.lastError }}
                            </p>
                            <p
                                v-if="!card.available"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                Not available yet.
                            </p>
                        </div>
                    </div>

                    <Button
                        v-if="card.status === 'connected'"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="disconnectForm.processing"
                        @click="disconnect(card)"
                    >
                        Disconnect
                    </Button>
                    <Button
                        v-else-if="card.available"
                        type="button"
                        size="sm"
                        :disabled="enableForm.processing"
                        @click="enable(card)"
                    >
                        {{
                            enableForm.processing
                                ? 'Enabling…'
                                : 'Enable delivery'
                        }}
                    </Button>
                </div>
            </section>
        </div>
    </div>
</template>
